<?php
/**
 * Copyright © 2013-2017 Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Payment\Gateway\Data;

use Magento\Payment\Model\InfoInterface;

/**
 * Service for creation transferable payment object from model
 *
 * @api
 */
interface PaymentDataObjectFactoryInterface
{
    /**
     * Creates Payment Data Object
     *
     * @param InfoInterface $paymentInfo
     * @return PaymentDataObjectInterface
     */
    public function create(InfoInterface $paymentInfo);
}
