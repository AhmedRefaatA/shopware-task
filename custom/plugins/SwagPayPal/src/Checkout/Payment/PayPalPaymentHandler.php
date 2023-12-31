<?php declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Swag\PayPal\Checkout\Payment;

use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\CartException;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AsynchronousPaymentHandlerInterface;
use Shopware\Core\Checkout\Payment\Exception\AsyncPaymentFinalizeException;
use Shopware\Core\Checkout\Payment\Exception\AsyncPaymentProcessException;
use Shopware\Core\Checkout\Payment\Exception\CustomerCanceledAsyncPaymentException;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Routing\Exception\MissingRequestParameterException;
use Shopware\Core\Framework\Routing\RoutingException;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateEntity;
use Swag\PayPal\Checkout\Payment\Handler\PayPalHandler;
use Swag\PayPal\Checkout\Payment\Handler\PlusPuiHandler;
use Swag\PayPal\Checkout\Payment\Method\AbstractPaymentMethodHandler;
use Swag\PayPal\RestApi\PartnerAttributionId;
use Swag\PayPal\RestApi\V2\Api\Common\Link;
use Swag\PayPal\Setting\Exception\PayPalSettingsInvalidException;
use Swag\PayPal\Setting\Service\SettingsValidationServiceInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

class PayPalPaymentHandler implements AsynchronousPaymentHandlerInterface
{
    public const PAYPAL_REQUEST_PARAMETER_CANCEL = 'cancel';
    public const PAYPAL_REQUEST_PARAMETER_PAYER_ID = 'PayerID';
    public const PAYPAL_REQUEST_PARAMETER_PAYMENT_ID = 'paymentId';
    public const PAYPAL_REQUEST_PARAMETER_TOKEN = 'token';
    public const PAYPAL_EXPRESS_CHECKOUT_ID = 'isPayPalExpressCheckout';
    public const PAYPAL_SMART_PAYMENT_BUTTONS_ID = 'isPayPalSpbCheckout';

    /**
     * @deprecated tag:v8.0.0 - Will be removed without replacement.
     */
    public const PAYPAL_PLUS_CHECKOUT_REQUEST_PARAMETER = 'isPayPalPlus';

    /**
     * @deprecated tag:v8.0.0 - Will be removed without replacement.
     */
    public const PAYPAL_PLUS_CHECKOUT_ID = 'isPayPalPlusCheckout';

    public const FINALIZED_ORDER_TRANSACTION_STATES = [
        OrderTransactionStates::STATE_PAID,
        OrderTransactionStates::STATE_AUTHORIZED,
    ];

    private OrderTransactionStateHandler $orderTransactionStateHandler;

    private PayPalHandler $payPalHandler;

    private PlusPuiHandler $plusPuiHandler;

    private EntityRepository $stateMachineStateRepository;

    private LoggerInterface $logger;

    private SettingsValidationServiceInterface $settingsValidationService;

    /**
     * @internal
     */
    public function __construct(
        OrderTransactionStateHandler $orderTransactionStateHandler,
        PayPalHandler $payPalHandler,
        PlusPuiHandler $plusPuiHandler,
        EntityRepository $stateMachineStateRepository,
        LoggerInterface $logger,
        SettingsValidationServiceInterface $settingsValidationService
    ) {
        $this->orderTransactionStateHandler = $orderTransactionStateHandler;
        $this->payPalHandler = $payPalHandler;
        $this->plusPuiHandler = $plusPuiHandler;
        $this->stateMachineStateRepository = $stateMachineStateRepository;
        $this->logger = $logger;
        $this->settingsValidationService = $settingsValidationService;
    }

    /**
     * @throws AsyncPaymentProcessException
     */
    public function pay(
        AsyncPaymentTransactionStruct $transaction,
        RequestDataBag $dataBag,
        SalesChannelContext $salesChannelContext
    ): RedirectResponse {
        $this->logger->debug('Started');
        $transactionId = $transaction->getOrderTransaction()->getId();

        try {
            $customer = $salesChannelContext->getCustomer();
            if ($customer === null) {
                throw CartException::customerNotLoggedIn();
            }

            $this->settingsValidationService->validate($salesChannelContext->getSalesChannelId());
            $this->orderTransactionStateHandler->processUnconfirmed($transactionId, $salesChannelContext->getContext());

            if ($dataBag->get(self::PAYPAL_EXPRESS_CHECKOUT_ID) || $dataBag->get(AbstractPaymentMethodHandler::PAYPAL_PAYMENT_ORDER_ID_INPUT_NAME)) {
                return $this->payPalHandler->handlePreparedOrder($transaction, $dataBag, $salesChannelContext);
            }

            if ($dataBag->getBoolean(self::PAYPAL_PLUS_CHECKOUT_ID)) {
                return $this->plusPuiHandler->handlePlusPayment($transaction, $dataBag, $salesChannelContext, $customer);
            }

            $response = $this->payPalHandler->handlePayPalOrder($transaction, $salesChannelContext, $customer);

            $link = $response->getRelLink(Link::RELATION_APPROVE);
            if ($link === null) {
                throw new AsyncPaymentProcessException($transactionId, 'No approve link provided by PayPal');
            }

            return new RedirectResponse($link->getHref());
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage(), ['error' => $e]);

            throw new AsyncPaymentProcessException($transactionId, $e->getMessage());
        }
    }

    /**
     * @throws AsyncPaymentFinalizeException
     * @throws CustomerCanceledAsyncPaymentException
     */
    public function finalize(
        AsyncPaymentTransactionStruct $transaction,
        Request $request,
        SalesChannelContext $salesChannelContext
    ): void {
        $this->logger->debug('Started');
        if ($this->transactionAlreadyFinalized($transaction, $salesChannelContext)) {
            $this->logger->debug('Already finalized');

            return;
        }

        if ($request->query->getBoolean(self::PAYPAL_REQUEST_PARAMETER_CANCEL)) {
            $this->logger->debug('Customer canceled');

            throw new CustomerCanceledAsyncPaymentException(
                $transaction->getOrderTransaction()->getId(),
                'Customer canceled the payment on the PayPal page'
            );
        }

        try {
            $this->settingsValidationService->validate($salesChannelContext->getSalesChannelId());
        } catch (PayPalSettingsInvalidException $exception) {
            throw new AsyncPaymentFinalizeException($transaction->getOrderTransaction()->getId(), $exception->getMessage());
        }

        $salesChannelId = $salesChannelContext->getSalesChannel()->getId();
        $context = $salesChannelContext->getContext();

        $paymentId = $request->query->get(self::PAYPAL_REQUEST_PARAMETER_PAYMENT_ID);

        $isExpressCheckout = $request->query->getBoolean(self::PAYPAL_EXPRESS_CHECKOUT_ID);
        $isSPBCheckout = $request->query->getBoolean(self::PAYPAL_SMART_PAYMENT_BUTTONS_ID);
        $isPlus = $request->query->getBoolean(self::PAYPAL_PLUS_CHECKOUT_REQUEST_PARAMETER);

        $partnerAttributionId = $this->getPartnerAttributionId($isExpressCheckout, $isSPBCheckout, $isPlus);

        if (\is_string($paymentId)) {
            $payerId = $request->query->get(self::PAYPAL_REQUEST_PARAMETER_PAYER_ID);
            if (!\is_string($payerId)) {
                if (\class_exists(RoutingException::class)) {
                    throw RoutingException::missingRequestParameter(self::PAYPAL_REQUEST_PARAMETER_PAYER_ID);
                } else {
                    /** @phpstan-ignore-next-line remove condition and keep if branch with min-version 6.5.2.0 */
                    throw new MissingRequestParameterException(self::PAYPAL_REQUEST_PARAMETER_PAYER_ID);
                }
            }

            $this->plusPuiHandler->handleFinalizePayment(
                $transaction,
                $salesChannelId,
                $context,
                $paymentId,
                $payerId,
                $partnerAttributionId
            );

            return;
        }

        $token = $request->query->get(self::PAYPAL_REQUEST_PARAMETER_TOKEN);
        if (!\is_string($token)) {
            if (\class_exists(RoutingException::class)) {
                throw RoutingException::missingRequestParameter(self::PAYPAL_REQUEST_PARAMETER_TOKEN);
            } else {
                /** @phpstan-ignore-next-line remove condition and keep if branch with min-version 6.5.2.0 */
                throw new MissingRequestParameterException(self::PAYPAL_REQUEST_PARAMETER_TOKEN);
            }
        }

        $this->payPalHandler->handleFinalizeOrder(
            $transaction,
            $token,
            $salesChannelId,
            $context,
            $partnerAttributionId
        );
    }

    private function getPartnerAttributionId(bool $isECS, bool $isSPB, bool $isPlus): string
    {
        if ($isECS) {
            return PartnerAttributionId::PAYPAL_EXPRESS_CHECKOUT;
        }

        if ($isSPB) {
            return PartnerAttributionId::SMART_PAYMENT_BUTTONS;
        }

        if ($isPlus) {
            return PartnerAttributionId::PAYPAL_PLUS;
        }

        return PartnerAttributionId::PAYPAL_CLASSIC;
    }

    private function transactionAlreadyFinalized(
        AsyncPaymentTransactionStruct $transaction,
        SalesChannelContext $salesChannelContext
    ): bool {
        $transactionStateMachineStateId = $transaction->getOrderTransaction()->getStateId();
        $criteria = new Criteria([$transactionStateMachineStateId]);

        /** @var StateMachineStateEntity|null $stateMachineState */
        $stateMachineState = $this->stateMachineStateRepository->search(
            $criteria,
            $salesChannelContext->getContext()
        )->get($transactionStateMachineStateId);

        if ($stateMachineState === null) {
            return false;
        }

        return \in_array(
            $stateMachineState->getTechnicalName(),
            self::FINALIZED_ORDER_TRANSACTION_STATES,
            true
        );
    }
}
