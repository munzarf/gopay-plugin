<?php

declare(strict_types=1);

namespace Bratiask\GoPayPlugin\Action;

use ArrayObject;
use Bratiask\GoPayPlugin\Api\GoPayApiInterface;
use Bratiask\GoPayPlugin\SetGoPay;
use Doctrine\Common\Collections\Collection;
use GoPay\Definition\Language;
use Payum\Core\Action\ActionInterface;
use Payum\Core\ApiAwareInterface;
use Payum\Core\Bridge\Spl\ArrayObject as CoreArrayObject;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\Exception\UnsupportedApiException;
use Payum\Core\Payum;
use Payum\Core\Reply\HttpRedirect;
use Payum\Core\Request\Generic;
use Payum\Core\Security\TokenInterface;
use Payum\Core\Storage\IdentityInterface;
use RuntimeException;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderItem;
use Webmozart\Assert\Assert;

/**
 * @phpstan-type ApiConfig array{
 *      goid: string,
 *      clientId: string,
 *      clientSecret: string,
 *      isProductionMode: bool
 *  }
 */
class GoPayAction implements ApiAwareInterface, ActionInterface
{
    /** @var ApiConfig */
    private array $api;

    public function __construct(
        private GoPayApiInterface $gopayApi,
        private Payum $payum,
    ) {
    }

    /**
     * @param Generic $request
     */
    public function execute($request): void
    {
        RequestNotSupportedException::assertSupports($this, $request);
        $model = CoreArrayObject::ensureArrayObject($request->getModel());

        $goId = $this->api['goid'];
        $clientId = $this->api['clientId'];
        $clientSecret = $this->api['clientSecret'];
        $isProductionMode = $this->api['isProductionMode'];
        /** @var string $locale */
        $locale = $model['locale'] ?? Language::CZECH;

        $gopayApi = $this->gopayApi;
        $gopayApi->authorize($goId, $clientId, $clientSecret, $isProductionMode, $locale);

        /** @var ?int $paymentId */
        $paymentId = $model['externalPaymentId'];
        if ($model['orderId'] === null || $paymentId === null) {
            /** @var TokenInterface $token */
            $token = $request->getToken();
            $order = $this->prepareOrder($token, $model, $goId);
            $response = $gopayApi->create($order);

            if (!isset($response->json['errors']) && GoPayApiInterface::CREATED === $response->json['state']) {
                $model['orderId'] = $response->json['order_number'];
                $model['externalPaymentId'] = $response->json['id'];
                $request->setModel($model);

                throw new HttpRedirect($response->json['gw_url']);
            }

            throw new RuntimeException('GoPay error: ' . $response->__toString());
        } else {
            $response = $gopayApi->retrieve($paymentId);

            $model['gopayStatus'] = $response->json['state'];
            if (GoPayApiInterface::CREATED === $response->json['state']) {
                $model['gopayStatus'] = GoPayApiInterface::CANCELED;
            }

            $request->setModel($model);
        }
    }

    public function supports($request): bool
    {
        return $request instanceof SetGoPay && $request->getModel() instanceof ArrayObject;
    }

    /**
     * @param ApiConfig $api
     */
    public function setApi($api): void
    {
        if (!is_array($api)) {
            throw new UnsupportedApiException('Not supported.');
        }

        $this->api = $api;
    }

    public function setGoPayApi(GoPayApiInterface $gopayApi): void
    {
        $this->gopayApi = $gopayApi;
    }

    /**
     * @return array<string, mixed>
     */
    private function prepareOrder(TokenInterface $token, CoreArrayObject $model, string $goid): array
    {
        $notifyToken = $this->createNotifyToken($token->getGatewayName(), $token->getDetails());

        $order = [];
        $order['target']['type'] = 'ACCOUNT';
        $order['target']['goid'] = $goid;
        $order['currency'] = $model['currencyCode'];
        $order['amount'] = $model['totalAmount'];
        $order['order_number'] = $model['extOrderId'];
        $order['lang'] = $model['locale'];

        /** @var CustomerInterface $customer */
        $customer = $model['customer'];

        Assert::isInstanceOf(
            $customer,
            CustomerInterface::class,
            sprintf(
                'Make sure the first model is the %s instance.',
                CustomerInterface::class,
            ),
        );

        $payerContact = [
            'email' => (string) $customer->getEmail(),
            'first_name' => (string) $customer->getFirstName(),
            'last_name' => (string) $customer->getLastName(),
        ];

        $order['payer']['contact'] = $payerContact;
        $order['items'] = $this->resolveProducts($model);

        $order['callback']['return_url'] = $token->getTargetUrl();
        $order['callback']['notification_url'] = $notifyToken->getTargetUrl();

        return $order;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function resolveProducts(CoreArrayObject $model): array
    {
        $items = [];
        /** @var Collection<OrderItem> $orderItems */
        $orderItems = $model['items'];
        foreach ($orderItems as $item) {
            $items[] = [
                'type' => 'ITEM',
                'name' => $item->getProductName(),
                'product_url' => '',
                'count' => $item->getQuantity(),
                'amount' => $item->getTotal(),
            ];
        }

        return $items;
    }

    private function createNotifyToken(string $gatewayName, IdentityInterface $model): TokenInterface
    {
        return $this->payum->getTokenFactory()->createNotifyToken($gatewayName, $model);
    }
}
