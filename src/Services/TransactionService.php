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

use Plenty\Modules\Plugin\DataBase\Contracts\DataBase;
use Plenty\Modules\Plugin\DataBase\Contracts\Query;
use Novalnet\Models\TransactionLog;
use Plenty\Plugin\Log\Loggable;
use Novalnet\Services\PaymentService;

/*** Class NovalnetNovalnetTransactionService*/
class TransactionService
{
    use Loggable;
	
    /*** Save data in NovalnetTransaction table*/
    public function saveTransaction($TransactionData)
    {
        try {
            $database = pluginApp(DataBase::class);
            $Transaction = pluginApp(TransactionLog::class);
            $Transaction->orderNo             = $TransactionData['order_no'];
            $Transaction->amount              = $TransactionData['amount'];
            $Transaction->referenceTid        = $TransactionData['ref_tid'];
            $Transaction->TransactionDatetime = date('Y-m-d H:i:s');
            $Transaction->tid                 = $TransactionData['tid'];
            $Transaction->paymentName         = $TransactionData['payment_name'];
    
            
            $database->save($NovalnetTransaction);
        } catch (\Exception $e) {
            $this->getLogger(__METHOD__)->error('Callback table insert failed!.', $e);
        }
    }

    /** * Retrieve NovalnetTransaction log table data*/
    public function getTransactionData($key, $value)
    {
        $database = pluginApp(DataBase::class);
        $order    = $database->query(TransactionLog::class)->where($key, '=', $value)->get();
        return $order;
    }
    
}




