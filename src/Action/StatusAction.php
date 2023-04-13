<?php

declare(strict_types=1);

namespace Bratiask\GoPayPlugin\Action;

use ArrayAccess;
use Bratiask\GoPayPlugin\Api\GoPayApiInterface;
use Payum\Core\Action\ActionInterface;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\Request\GetStatusInterface;

class StatusAction implements ActionInterface
{
    /**
     * @param GetStatusInterface $request
     */
    public function execute($request): void
    {
        RequestNotSupportedException::assertSupports($this, $request);

        $model = ArrayObject::ensureArrayObject($request->getModel());
        $status = $model['gopayStatus'] ?? null;

        if ((null === $status || GoPayApiInterface::CREATED === $status) && false === isset($model['orderId'])) {
            $request->markNew();

            return;
        }

        if (GoPayApiInterface::CANCELED === $status) {
            $request->markCanceled();

            return;
        }

        if (GoPayApiInterface::TIMEOUTED === $status) {
            $request->markCanceled();

            return;
        }

        if (GoPayApiInterface::PAID === $status) {
            $request->markCaptured();

            return;
        }

        $request->markUnknown();
    }

    public function supports($request): bool
    {
        return $request instanceof GetStatusInterface && $request->getModel() instanceof ArrayAccess;
    }
}
