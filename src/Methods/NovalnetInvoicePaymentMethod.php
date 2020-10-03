<?php
/**
 * This module is used for real time processing of
 * Novalnet payment module of customers.
 * This free contribution made by request.
 * 
 * If you have found this script useful a small
 * recommendation as well as a comment on merchant form
 * would be greatly appreciated.
 *
 * @author       Novalnet AG
 * @copyright(C) Novalnet
 * All rights reserved. https://www.Novalnet.de/payment-plugins/kostenlos/lizenz
 */

namespace Novalnet\Methods;

use Plenty\Plugin\ConfigRepository;
use Plenty\Modules\Payment\Method\Contracts\PaymentMethodService;
use Plenty\Plugin\Application;
use Novalnet\Helper\PaymentHelper;
use Novalnet\Services\PaymentService;
use Plenty\Modules\Basket\Models\Basket;
use Plenty\Modules\Basket\Contracts\BasketRepositoryContract;

/**
 * Class NovalnetPaymentMethod
 *
 * @package Novalnet\Methods
 */
class NovalnetInvoicePaymentMethod extends PaymentMethodService
{
    /**
     * @var ConfigRepository
     */
    private $configRepository;

    /**
     * @var PaymentHelper
     */
    private $paymentHelper;
    
    /**
	 * @var PaymentService
	 */
	private $paymentService;
	
		/**
     * @var Basket
     */
    private $basket;

    /*** NovalnetPaymentMethod constructor.*/
    public function __construct(ConfigRepository $configRepository,
                                PaymentHelper $paymentHelper,
                                PaymentService $paymentService,
			       BasketRepositoryContract $basket)
    {
        $this->configRepository = $configRepository;
        $this->paymentHelper = $paymentHelper;
        $this->paymentService  = $paymentService;
	    $this->basket = $basket->load();
    }

    /*** Check the configuration if the payment method is active
     * Return true only if the payment method is active*/
    public function isActive():bool
    {
       if ($this->configRepository->get('Novalnet.Novalnet_invoice_payment_active') == 'true') 
       {
	    
        return (bool)($this->paymentHelper->paymentActive());
        } 
        return false;
    
    }

    /*** Get the name of the payment method. The name can be entered in the configuration. */
    public function getName():string
    {   
		$name = trim($this->configRepository->get('Novalnet.Novalnet_invoice_payment_name'));
        return ($name ? $name : $this->paymentHelper->getTranslatedText('Novalnet_invoice'));
    }

    /*** Retrieves the icon of the payment. The URL can be entered in the configuration.*/
    public function getIcon():string
    {
        $logoUrl = $this->configRepository->get('Novalnet.Novalnet_invoice_payment_logo');
        if($logoUrl == 'images/invoice.png'){
            /** @var Application $app */
            $app = pluginApp(Application::class);
            $logoUrl = $app->getUrlPath('Novalnet') .'/images/invoice.png';
        } 
        return $logoUrl;
    }
    /*** Retrieves the description of the payment. The description can be entered in the configuration. */
    public function getDescription():string
    {
		$description = trim($this->configRepository->get('Novalnet.Novalnet_invoice_description'));
        	return ($description ? $description : $this->paymentHelper->getTranslatedText('invoice_prepayment_payment_description'));
    }

    /*** Check if it is allowed to switch to this payment method */
    public function isSwitchableTo(): bool
    {
        return false;
    }

    /*** Check if it is allowed to switch from this payment method*/
    public function isSwitchableFrom(): bool
    {
        return false;
    }
}

