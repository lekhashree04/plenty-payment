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

namespace Novalnet\Services;

use Plenty\Modules\Basket\Models\Basket;
use Plenty\Plugin\ConfigRepository;
use Plenty\Modules\Frontend\Session\Storage\Contracts\FrontendSessionStorageFactoryContract;
use Plenty\Modules\Account\Address\Contracts\AddressRepositoryContract;
use Plenty\Modules\Order\Shipping\Countries\Contracts\CountryRepositoryContract;
use Plenty\Modules\Helper\Services\WebstoreHelper;
use Novalnet\Helper\PaymentHelper;
use Plenty\Plugin\Log\Loggable;
use Plenty\Modules\Frontend\Services\AccountService;
use Novalnet\Constants\NovalnetConstants;
use Novalnet\Services\TransactionService;
use Plenty\Modules\Plugin\DataBase\Contracts\DataBase;
use Plenty\Modules\Plugin\DataBase\Contracts\Query;
use Novalnet\Models\TransactionLog;
use Plenty\Modules\Payment\History\Contracts\PaymentHistoryRepositoryContract;
use Plenty\Modules\Payment\History\Models\PaymentHistory as PaymentHistoryModel;
use Plenty\Modules\Payment\Contracts\PaymentRepositoryContract;
/**
 * Class PaymentService
 *
 * @package Novalnet\Services
 */
class PaymentService
{

    use Loggable;
    
    /**
     * @var PaymentHistoryRepositoryContract
     */
    private $paymentHistoryRepo;
    
   /**
     * @var PaymentRepositoryContract
     */
    private $paymentRepository;

    /**
     * @var ConfigRepository
     */
    private $config;
   
    /**
     * @var FrontendSessionStorageFactoryContract
     */
    private $sessionStorage;

    /**
     * @var AddressRepositoryContract
     */
    private $addressRepository;

    /**
     * @var CountryRepositoryContract
     */
    private $countryRepository;

    /**
     * @var WebstoreHelper
     */
    private $webstoreHelper;

    /**
     * @var PaymentHelper
     */
    private $paymentHelper;
    
    /**
     * @var TransactionLogData
     */
    private $transactionLogData;
    
    

    /**
     * Constructor.
     *
     * @param ConfigRepository $config
     * @param FrontendSessionStorageFactoryContract $sessionStorage
     * @param AddressRepositoryContract $addressRepository
     * @param CountryRepositoryContract $countryRepository
     * @param WebstoreHelper $webstoreHelper
     * @param PaymentHelper $paymentHelper
     * @param TransactionService $transactionLogData
     */
    public function __construct(ConfigRepository $config,
                                FrontendSessionStorageFactoryContract $sessionStorage,
                                AddressRepositoryContract $addressRepository,
                                CountryRepositoryContract $countryRepository,
                                WebstoreHelper $webstoreHelper,
                                PaymentHelper $paymentHelper,
                                PaymentHistoryRepositoryContract $paymentHistoryRepo,
                                PaymentRepositoryContract $paymentRepository,
                                TransactionService $transactionLogData)
    {
        $this->config                   = $config;
        $this->sessionStorage           = $sessionStorage;
        $this->addressRepository        = $addressRepository;
        $this->countryRepository        = $countryRepository;
        $this->webstoreHelper           = $webstoreHelper;
        $this->paymentHistoryRepo       = $paymentHistoryRepo;
        $this->paymentRepository        = $paymentRepository;
        $this->paymentHelper            = $paymentHelper;
        $this->transactionLogData       = $transactionLogData;
    }
    
    /*** Validate  the response data.*/
    public function validateResponse()
    {
        $nnPaymentData = $this->sessionStorage->getPlugin()->getValue('nnPaymentData');
        $lang = strtolower((string)$nnPaymentData['lang']);
        $this->sessionStorage->getPlugin()->setValue('nnPaymentData', null);
       
        $nnPaymentData['mop']            = $this->sessionStorage->getPlugin()->getValue('mop');
        $nnPaymentData['payment_method'] = strtolower($this->paymentHelper->getPaymentKeyByMop($nnPaymentData['mop']));
        
        $this->executePayment($nnPaymentData); 
        $this->transactionLogData->saveTransaction($transactionData);

     }
     

    /*** Build Novalnet server request parameters*/
    public function getRequestParameters(Basket $basket, $paymentKey = '')
    {
        
     /** @var \Plenty\Modules\Frontend\Services\VatService $vatService */
        $vatService = pluginApp(\Plenty\Modules\Frontend\Services\VatService::class);

        //we have to manipulate the basket because its stupid and doesnt know if its netto or gross
        if(!count($vatService->getCurrentTotalVats())) {
            $basket->itemSum = $basket->itemSumNet;
            $basket->shippingAmount = $basket->shippingAmountNet;
            $basket->basketAmount = $basket->basketAmountNet;
        }
        
        $billingAddressId = $basket->customerInvoiceAddressId;
        $address = $this->addressRepository->findAddressById($billingAddressId);
        if(!empty($basket->customerShippingAddressId)){
            $shippingAddress = $this->addressRepository->findAddressById($basket->customerShippingAddressId);
        }
    
        foreach ($address->options as $option) {
        if ($option->typeId == 12) {
                $name = $option->value;
        }
        }
        $customerName = explode(' ', $name);
        $firstname = $customerName[0];
        if( count( $customerName ) > 1 ) {
            unset($customerName[0]);
            $lastname = implode(' ', $customerName);
        } else {
            $lastname = $firstname;
        }
        $firstName = empty ($firstname) ? $lastname : $firstname;
        $lastName = empty ($lastname) ? $firstname : $lastname;
    
        $account = pluginApp(AccountService::class);
        $customerId = $account->getAccountContactId();
        $paymentKeyLower = strtolower((string) $paymentKey);
        $testModeKey = 'Novalnet.' . $paymentKeyLower . '_test_mode';

        $paymentRequestData = [
            'vendor'             => $this->paymentHelper->getNovalnetConfig('Novalnet_vendor_id'),
            'auth_code'          => $this->paymentHelper->getNovalnetConfig('Novalnet_auth_code'),
            'product'            => $this->paymentHelper->getNovalnetConfig('Novalnet_product_id'),
            'tariff'             => $this->paymentHelper->getNovalnetConfig('Novalnet_tariff_id'),
            'test_mode'          => (int)($this->config->get($testModeKey) == 'true'),
            'first_name'         => !empty($address->firstName) ? $address->firstName : $firstName,
            'last_name'          => !empty($address->lastName) ? $address->lastName : $lastName,
            'email'              => $address->email,
            'gender'             => 'u',
            'city'               => $address->town,
            'street'             => $address->street,
            'country_code'       => $this->countryRepository->findIsoCode($address->countryId, 'iso_code_2'),
            'zip'                => $address->postalCode,
            'customer_no'        => ($customerId) ? $customerId : 'guest',
            'lang'               => strtoupper($this->sessionStorage->getLocaleSettings()->language),
            'amount'             => $this->paymentHelper->ConvertAmountToSmallerUnit($basket->basketAmount),
            'currency'           => $basket->currency,
            'remote_ip'          => $this->paymentHelper->getRemoteAddress(),
            'system_ip'          => $this->paymentHelper->getServerAddress(),
            'system_url'         => $this->webstoreHelper->getCurrentWebstoreConfiguration()->domainSsl,
            'system_name'        => 'Plentymarkets',
            'system_version'     => NovalnetConstants::PLUGIN_VERSION,
            'notify_url'         => $this->webstoreHelper->getCurrentWebstoreConfiguration()->domainSsl . '/payment/Novalnet/callback/',
            'key'                => $this->getkeyByPaymentKey($paymentKey),
            'payment_type'       => $this->getTypeByPaymentKey($paymentKey)
        ];

        if(!empty($address->houseNumber))
        {
            $paymentRequestData['house_no'] = $address->houseNumber;
        }
        else
        {
            $paymentRequestData['search_in_street'] = '1';
        }

        if(!empty($address->companyName)) {
            $paymentRequestData['company'] = $address->companyName;
        } elseif(!empty($shippingAddress->companyName)) {
            $paymentRequestData['company'] = $shippingAddress->companyName;
        }

        if(!empty($address->phone)) {
            $paymentRequestData['tel'] = $address->phone;
        }

        if(is_numeric($referrerId = $this->paymentHelper->getNovalnetConfig('referrer_id'))) {
            $paymentRequestData['referrer_id'] = $referrerId;
        }
        $url = $this->getPaymentData($paymentKey, $paymentRequestData);
        return [
            'data' => $paymentRequestData,
            'url'  => $url
        ];
    }

    /*** Get payment related param*/
    public function getPaymentData($paymentKey, &$paymentRequestData )
    {
        $url = $this->getpaymentUrl($paymentKey);
     if($paymentKey == 'Novalnet_INVOICE') 
     {
                    $paymentRequestData['invoice_type'] = 'INVOICE';
                    $invoiceDueDate = $this->paymentHelper->getNovalnetConfig('Novalnet_invoice_due_date');
                    if(is_numeric($invoiceDueDate)) 
                    {
                        $paymentRequestData['due_date'] = $this->paymentHelper->dateFormatter($invoiceDueDate);
                    }
        }
      
        
        return $url;
    }
    
    /*** Get the direct payment process controller URL to be handled*/
    public function getProcessPaymentUrl()
    {
        return $this->webstoreHelper->getCurrentWebstoreConfiguration()->domainSsl . '/payment/Novalnet/processPayment/';
    }
    /*** Get the payment process URL by using plenty payment key*/
    public function getpaymentUrl($paymentKey)
    {
        $payment = [
            'Novalnet_INVOICE'=>NovalnetConstants::PAYPORT_URL
        ];

        return $payment[$paymentKey];
    }


   /*** Get payment key by plenty payment key*/
    public function getkeyByPaymentKey($paymentKey)
    {
        $payment = [
            'Novalnet_INVOICE'=>'27'
        ];

        return $payment[$paymentKey];
    }

    /*** Get payment type by plenty payment Key*/
    public function getTypeByPaymentKey($paymentKey)
    {
        $payment = [
            'Novalnet_INVOICE'=>'INVOICE_START'
        ];

        return $payment[$paymentKey];
    }

   
    
    /*** Send the payment call*/
	public function paymentCalltoNovalnetServer () {
		  
		$serverRequestData = $this->sessionStorage->getPlugin()->getValue('nnPaymentData');
		$serverRequestData['data']['order_no'] = $this->sessionStorage->getPlugin()->getValue('nnOrderNo');
		$response = $this->paymentHelper->executeCurl($serverRequestData['data'], $serverRequestData['url']);
		$responseData = $this->paymentHelper->convertStringToArray($response['response'], '&');
		$notificationMessage = $this->paymentHelper->getNovalnetStatusText($responseData);
		$responseData['payment_id'] = (!empty($responseData['payment_id'])) ? $responseData['payment_id'] : $responseData['key'];
		$isPaymentSuccess = isset($responseData['status']) && $responseData['status'] == '100';
		
		if($isPaymentSuccess)
		{           
			if(isset($serverRequestData['data']['pan_hash']))
			{
				unset($serverRequestData['data']['pan_hash']);
			}
			
			$this->sessionStorage->getPlugin()->setValue('nnPaymentData', array_merge($serverRequestData['data'], $responseData));
			$this->pushNotification($notificationMessage, 'success', 100);
			
		} else {
			$orderStatus = trim($this->config->get('Novalnet.Novalnet_order_cancel_status'));
			$this->paymentHelper->updateOrderStatus((int)$responseData['order_no'], $orderStatus);
			$this->pushNotification($notificationMessage, 'error', 100);
		}
		  
	}

    
    
}
