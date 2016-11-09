<?php
/**
 * Copyright � 2015 Pay.nl All rights reserved.
 */
namespace Byjuno\ByjunoCore\Controller\Checkout;
use Byjuno\ByjunoCore\Helper\DataHelper;
use Byjuno\ByjunoCore\Helper\Api\ByjunoLogger;
use Magento\Framework\App\Action\Action;

class Startpayment extends Action
{
    protected $_config;
    /**
     * @var DataHelper
     */
    protected $_dataHelper;
    /**
     * @var \Magento\Checkout\Model\Session
     */
    protected $_checkoutSession;
    /**
     * @var \Magento\Catalog\Model\Session
     */
    protected $_logger;
    /**
     * Index constructor.
     * @param \Magento\Framework\App\Action\Context $context
     * @param Session $checkoutSession
     * @param \Psr\Log\LoggerInterface $logger
     */
    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \Magento\Checkout\Model\Session $checkoutSession,
        DataHelper $helper,
        ByjunoLogger $logger
    )
    {
       // $this->_response = $response;
       // $this->_communicator = $communicator;
        $this->_checkoutSession = $checkoutSession;
        $this->_dataHelper = $helper;
        $this->_logger = $logger;
        parent::__construct($context);
    }
    public function execute()
    {

        try {
            $order = $this->_checkoutSession->getLastRealOrder();
            /* @var $payment \Magento\Sales\Model\Order\Payment */
            $payment = $order->getPayment();
            $request = $this->_dataHelper->CreateMagentoShopRequestOrder($order, $payment, '', '');

            $ByjunoRequestName = "Order request";
            $requestType = 'b2c';
            if ($request->getCompanyName1() != '' && $this->_dataHelper->_scopeConfig->getValue('byjunocheckoutsettings/byjuno_setup/businesstobusiness', \Magento\Store\Model\ScopeInterface::SCOPE_STORE) == 'enable') {
                $ByjunoRequestName = "Order request for Company";
                $requestType = 'b2b';
                $xml = $request->createRequestCompany();
            } else {
                $xml = $request->createRequest();
            }
            $mode = $this->_dataHelper->_scopeConfig->getValue('byjunocheckoutsettings/byjuno_setup/currentmode', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
            if ($mode == 'production') {
                $this->_dataHelper->_communicator->setServer('live');
            } else {
                $this->_dataHelper->_communicator->setServer('test');
            }
            $response = $this->_dataHelper->_communicator->sendRequest($xml, (int)$this->_dataHelper->_scopeConfig->getValue('byjunocheckoutsettings/byjuno_setup/timeout', \Magento\Store\Model\ScopeInterface::SCOPE_STORE));
            $status = 0;
            if ($response) {
                $this->_dataHelper->_response->setRawResponse($response);
                $this->_dataHelper->_response->processResponse();
                $status = (int)$this->_dataHelper->_response->getCustomerRequestStatus();
                $this->_checkoutSession->setByjunoTransaction($this->_dataHelper->_response->getTransactionNumber());
                //$this->_dataHelper->saveLog($quote, $request, $xml, $response, $status, $ByjunoRequestName);
                if (intval($status) > 15) {
                    $status = 0;
                }
                $trxId = $this->_dataHelper->_response->getResponseId();
            } else {
                //$this->getHelper()->saveLog($quote, $request, $xml, "empty response", "0", $ByjunoRequestName);
                $trxId = "empty";
            }
            $payment->setTransactionId($trxId);
            $payment->setParentTransactionId($payment->getTransactionId());

            $transaction = $payment->addTransaction(\Magento\Sales\Model\Order\Payment\Transaction::TYPE_AUTH, null, true);
            if ($status == 2) {
                $transaction->setIsClosed(false);
            } else {
                $transaction->setIsClosed(true);
            }
            $transaction->save();
            $payment->save();

            $this->_checkoutSession->setIntrumStatus("intrum_status", $status);
            $this->_checkoutSession->setIntrumRequestType("intrum_request_type", $requestType);
            $this->_checkoutSession->setIntrumOrder("intrum_order", $order->getId());
            $resultRedirect = $this->resultRedirectFactory->create();
            if ($status == 2) {
                $resultRedirect->setPath('checkout/onepage/success');
            } else if ($status == 0) {
                $error = $this->_dataHelper->getByjunoErrorMessage($status, $requestType);
                $order->registerCancellation($error)->save();
                $this->_checkoutSession->restoreQuote();
                $this->messageManager->addExceptionMessage(new \Exception($status), $error);
                $resultRedirect->setPath('checkout/cart');
            } else {
                $error = $this->_dataHelper->getByjunoErrorMessage($status, $requestType);
                $order->registerCancellation($error)->save();
                $this->_checkoutSession->restoreQuote();
                $this->messageManager->addExceptionMessage(new \Exception($status), $error);
                $resultRedirect->setPath('checkout/cart');
            }
        } catch (\Exception $e) {
            $order = $this->_checkoutSession->getLastRealOrder();
            $error = __("Unexpected error");
            $order->registerCancellation($error)->save();
            $this->_checkoutSession->restoreQuote();
            $this->messageManager->addExceptionMessage(new \Exception("ex"), $error);
            $resultRedirect = $this->resultRedirectFactory->create();
            $resultRedirect->setPath('checkout/cart');
        }
        return $resultRedirect;

    }
}