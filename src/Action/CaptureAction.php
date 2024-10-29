<?php

declare(strict_types=1);

namespace Bratiask\GoPayPlugin\Action;

use ArrayAccess;
use Bratiask\GoPayPlugin\SetGoPay;
use Payum\Core\Action\ActionInterface;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\GatewayAwareInterface;
use Payum\Core\GatewayAwareTrait;
use Payum\Core\Request\Capture;
use Payum\Core\Security\TokenInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;

final class CaptureAction implements ActionInterface, GatewayAwareInterface
{
    use GatewayAwareTrait;

    /**
     * @param Capture $request
     */
    public function execute($request): void
    {
        RequestNotSupportedException::assertSupports($this, $request);
        $model = $request->getModel();
        $model = ArrayObject::ensureArrayObject($model);

        /** @var PaymentInterface $payment */
        $payment = $request->getFirstModel();

        /** @var OrderInterface $order */
        $order = $payment->getOrder();
        /** @var TokenInterface $token */
        $token = $request->getToken();
        assert($order instanceof OrderInterface);
        assert($token instanceof TokenInterface);

        $model['items'] = $order->getItems();
        $model['customer'] = $order->getCustomer();
        $model['locale'] = $this->fallbackLocaleCode($order->getLocaleCode() ?? '');

        $this->gateway->execute($this->goPayAction($token, $model));
    }

    public function supports($request): bool
    {
        return $request instanceof Capture && $request->getModel() instanceof ArrayAccess;
    }

    private function goPayAction(TokenInterface $token, ArrayObject $model): SetGoPay
    {
        $gopayAction = new SetGoPay($token);
        $gopayAction->setModel($model);

        return $gopayAction;
    }

    private function fallbackLocaleCode(string $localeCode): string
    {
        return explode('_', $localeCode)[0];
    }
}
