<?php

declare(strict_types=1);

namespace Bratiask\GoPayPlugin\Action;

use ArrayObject;
use Bratiask\GoPayPlugin\Api\GoPayApiInterface;
use Exception;
use GoPay\Definition\Language;
use Payum\Core\Action\ActionInterface;
use Payum\Core\ApiAwareInterface;
use Payum\Core\Bridge\Spl\ArrayObject as CoreArrayObject;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\Exception\UnsupportedApiException;
use Payum\Core\GatewayAwareTrait;
use Payum\Core\Reply\HttpResponse;
use Payum\Core\Request\Notify;
use Sylius\Component\Core\Model\PaymentInterface;
use Webmozart\Assert\Assert;

/**
 * @phpstan-import-type ApiConfig from GoPayAction
 */
final class NotifyAction implements ActionInterface, ApiAwareInterface
{
    use GatewayAwareTrait;

    /** @var ApiConfig */
    private array $api;

    public function __construct(private GoPayApiInterface $gopayApi)
    {
    }

    /**
     * @param Notify $request
     */
    public function execute($request): void
    {
        RequestNotSupportedException::assertSupports($this, $request);

        /** @var PaymentInterface $payment */
        $payment = $request->getFirstModel();
        Assert::isInstanceOf($payment, PaymentInterface::class);
        $model = CoreArrayObject::ensureArrayObject($request->getModel());

        /** @var string $locale */
        $locale = $model['locale'] ?? Language::CZECH;

        $this->gopayApi->authorize(
            $this->api['goid'],
            $this->api['clientId'],
            $this->api['clientSecret'],
            $this->api['isProductionMode'],
            $locale,
        );

        try {
            /** @var int $paymentId */
            $paymentId = $model['externalPaymentId'];
            $response = $this->gopayApi->retrieve($paymentId);
            $model['gopayStatus'] = $response->json['state'];
            if (GoPayApiInterface::CREATED === $response->json['state']) {
                $model['gopayStatus'] = GoPayApiInterface::CANCELED;
            }

            $request->setModel($model);

            throw new HttpResponse('SUCCESS');
        } catch (Exception $e) {
            throw new HttpResponse($e->getMessage());
        }
    }

    public function supports($request): bool
    {
        return $request instanceof Notify && $request->getModel() instanceof ArrayObject;
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
}
