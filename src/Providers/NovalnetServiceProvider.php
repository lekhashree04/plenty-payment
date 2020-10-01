

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
 * All rights reserved. https://www.novalnet.de/payment-plugins/kostenlos/lizenz
 */

namespace Novalnet\Providers;

use Plenty\Plugin\ServiceProvider;
use Plenty\Plugin\Events\Dispatcher;
use Plenty\Modules\Payment\Events\Checkout\ExecutePayment;
use Plenty\Modules\Payment\Events\Checkout\GetPaymentMethodContent;
use Plenty\Modules\Basket\Events\Basket\AfterBasketCreate;
use Plenty\Modules\Basket\Events\Basket\AfterBasketChanged;
use Plenty\Modules\Basket\Events\BasketItem\AfterBasketItemAdd;
use Plenty\Modules\Payment\Method\Contracts\PaymentMethodContainer;
use Plenty\Modules\Basket\Contracts\BasketRepositoryContract;
use Plenty\Modules\Payment\Method\Contracts\PaymentMethodRepositoryContract;
use Plenty\Modules\Account\Address\Contracts\AddressRepositoryContract;
use Plenty\Modules\Frontend\Session\Storage\Contracts\FrontendSessionStorageFactoryContract;
use Plenty\Plugin\Log\Loggable;
use Novalnet\Helper\PaymentHelper;
use Novalnet\Services\PaymentService;
use Novalnet\Services\TransactionService;
use Plenty\Plugin\Templates\Twig;
use Plenty\Plugin\ConfigRepository;
use Plenty\Modules\Order\Pdf\Events\OrderPdfGenerationEvent;
use Plenty\Modules\Order\Pdf\Models\OrderPdfGeneration;
use Plenty\Modules\Payment\Contracts\PaymentRepositoryContract;
use Plenty\Modules\Plugin\DataBase\Contracts\DataBase;
use Plenty\Modules\Plugin\DataBase\Contracts\Query;
use Novalnet\Models\TransactionLog;
use Plenty\Modules\Document\Models\Document;
use Novalnet\Constants\NovalnetConstants;

use Novalnet\Methods\NovalnetInvoicePaymentMethod;
use Plenty\Modules\EventProcedures\Services\Entries\ProcedureEntry;
use Plenty\Modules\EventProcedures\Services\EventProceduresService;
use Novalnet\Controllers\PaymentController;
/**
 * Class NovalnetServiceProvider
 *
 * @package Novalnet\Providers
 */
class NovalnetServiceProvider extends ServiceProvider
{
    use Loggable;

    /**
     * Register the route service provider
     */
    public function register()
    {
        $this->getApplication()->register(NovalnetRouteServiceProvider::class);
    }

    /**
     * Boot additional services for the payment method
     *
     * @param Dispatcher $eventDispatcher
     * @param paymentHelper $paymentHelper
     * @param PaymentService $paymentService
     * @param BasketRepositoryContract $basketRepository
     * @param PaymentMethodContainer $payContainer
     * @param PaymentMethodRepositoryContract $paymentMethodService
     * @param FrontendSessionStorageFactoryContract $sessionStorage
     * @param TransactionService $transactionLogData
     * @param Twig $twig
     * @param ConfigRepository $config
     */
    public function boot( Dispatcher $eventDispatcher,
                          PaymentHelper $paymentHelper,
                          AddressRepositoryContract $addressRepository,
                          PaymentService $paymentService,
                          BasketRepositoryContract $basketRepository,
                          PaymentMethodContainer $payContainer,
                          PaymentMethodRepositoryContract $paymentMethodService,
                          FrontendSessionStorageFactoryContract $sessionStorage,
                          TransactionService $transactionLogData,
                          Twig $twig,
                          ConfigRepository $config,
                          PaymentRepositoryContract $paymentRepository,
                          DataBase $dataBase,
                          EventProceduresService $eventProceduresService)
{

        // Register the Novalnet payment methods in the payment method container
        $payContainer->register('plenty_novalnet::NOVALNET_INVOICE', NovalnetInvoicePaymentMethod::class,
            [
                AfterBasketChanged::class,
                AfterBasketItemAdd::class,
                AfterBasketCreate::class
            ]);
        
        // Listen for the event that gets the payment method content
        $eventDispatcher->listen(GetPaymentMethodContent::class,
                function(GetPaymentMethodContent $event) use($config, $paymentHelper, $addressRepository, $paymentService, $basketRepository, $paymentMethodService, $sessionStorage, $twig)
                {
                    if($paymentHelper->getPaymentKeyByMop($event->getMop()))
                        {   
                          $paymentKey = $paymentHelper->getPaymentKeyByMop($event->getMop()); 
                          $basket = $basketRepository->load();            
                          $billingAddressId = $basket->customerInvoiceAddressId;
                          $address = $addressRepository->findAddressById($billingAddressId);
                            foreach ($address->options as $option)
                            {
                               if ($option->typeId == 12) 
                                  {
                                      $name = $option->value;
                                  }
                                  if ($option->typeId == 9) 
                                  {
                                      $birthday = $option->value;
                                   }
                             }
                        $customerName = explode(' ', $name);
                        $firstname = $customerName[0];
                        if( count( $customerName ) > 1 ) 
                        {
                            unset($customerName[0]);
                            $lastname = implode(' ', $customerName);
                        } else 
                        {
                            $lastname = $firstname;
                        }
                        $firstName = empty ($firstname) ? $lastname : $firstname;
                        $lastName = empty ($lastname) ? $firstname : $lastname;
                            $endCustomerName = $firstName .' '. $lastName;
                            $endUserName = $address->firstName .' '. $address->lastName;

                        $name = trim($config->get('Novalnet.' . strtolower($paymentKey) . '_payment_name'));
                        $paymentName = ($name ? $name : $paymentHelper->getTranslatedText(strtolower($paymentKey)));
                        $redirect = $paymentService->isRedirectPayment($paymentKey);    
                            
                           if (empty($serverRequestData['data']['first_name']) && empty($serverRequestData['data']['last_name'])) 
                              {
                                     $content = $paymentHelper->getTranslatedText('nn_first_last_name_error');
                                     $contentType = 'errorCode';   
                              } else 
                              {
                                 $sessionStorage->getPlugin()->setValue('nnPaymentData', $serverRequestData['data']);
                                        $sessionStorage->getPlugin()->setValue('nnPaymentUrl', $serverRequestData['url']);
                                        $content = '';
                                        $contentType = 'continue';
                              }
                        } 
                        else if(in_array($paymentKey, ['NOVALNET_INVOICE']))
                                {
                                        $processDirect = true;
                                        $B2B_customer   = false;
                                        if($paymentKey == 'NOVALNET_INVOICE')
                                        {
                                              $guaranteeStatus = $paymentService->getGuaranteeStatus($basketRepository->load(), $paymentKey);
                                            if($guaranteeStatus != 'normal' && $guaranteeStatus != 'guarantee')
                                              {
                                                 $processDirect = false;
                                                 $contentType = 'errorCode';
                                                  $content = $guaranteeStatus;
                                              }else{
                                                  $processDirect = true;                                              
                                                  $B2B_customer  = true;
                                                    }
                                        }
                                    }
                                    if ($processDirect) 
                                    {
                                          $content = '';
                                          $contentType = 'continue';
                                          $serverRequestData = $paymentService->getRequestParameters($basketRepository->load(), $paymentKey);
                                          if (empty($serverRequestData['data']['first_name']) && empty($serverRequestData['data']['last_name']))
                                            {
                                              $content = $paymentHelper->getTranslatedText('nn_first_last_name_error');
                                              $contentType = 'errorCode';   
                                            } 
                                          $sessionStorage->getPlugin()->setValue('nnPaymentData', $serverRequestData);
                                    
                                    }
                                    
                               
                                $event->setValue($content);
                                $event->setType($contentType);
                        } 
                });

        // Listen for the event that executes the payment
        $eventDispatcher->listen(ExecutePayment::class,
            function (ExecutePayment $event) use ($paymentHelper, $paymentService, $sessionStorage, $transactionLogData,$config,$basketRepository)
            {
                if($paymentHelper->getPaymentKeyByMop($event->getMop())) 
                {
                    $sessionStorage->getPlugin()->setValue('nnOrderNo',$event->getOrderId());
                    $sessionStorage->getPlugin()->setValue('mop',$event->getMop());
                    $paymentKey = $paymentHelper->getPaymentKeyByMop($event->getMop());
                    $sessionStorage->getPlugin()->setValue('paymentkey', $paymentKey);

                    if(!$paymentService->isRedirectPayment($paymentKey)) 
                    {
						             $paymentService->paymentCalltoNovalnetServer();
                         $paymentService->validateResponse();
                    } else 
                    {
                        $paymentProcessUrl = $paymentService->validateResponse();
                        $event->setType('redirectUrl');
                        $event->setValue($paymentProcessUrl);
                    }
                }
            });   
    }
}

