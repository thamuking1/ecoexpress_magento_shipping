<?php

namespace Ecoexpress\Carrier\Controller\Adminhtml\Index;

use Magento\Framework\Controller\ResultFactory;
use \Magento\Backend\Model\Session;

class Shipment extends \Magento\Backend\App\Action
{

    const XML_PATH_TRANS_IDENTITY_EMAIL = 'trans_email/ident_general/email';

    const XML_PATH_TRANS_IDENTITY_NAME = 'trans_email/ident_general/name';

    const XML_PATH_SHIPMENT_EMAIL_TEMPLATE = 'ecoexpress/template/shipment_template';

    const XML_PATH_SHIPMENT_EMAIL_COPY_TO = 'ecoexpress/template/copy_to';

    const XML_PATH_SHIPMENT_EMAIL_COPY_METHOD = 'ecoexpress/template/copy_method';

    private $resultPageFactory;

    private $request;

    private $scopeConfig;

    private $shipmentLoader;

    private $transportBuilder;

    private $storeManager;

    private $helper;

    private $order;

    private $transaction;

    private $resultJsonFactory;

    private $tracking;

    protected $curl;

    public function __construct(
        \Magento\Backend\App\Action\Context $context,
        \Magento\Framework\View\Result\PageFactory $resultPageFactory,
        \Magento\Shipping\Controller\Adminhtml\Order\ShipmentLoader $shipmentLoader,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Framework\Mail\Template\TransportBuilder $transportBuilder,
        \Magento\Framework\Controller\Result\JsonFactory $resultJsonFactory,
        \Ecoexpress\Carrier\Helper\Data $helper,
        \Magento\Sales\Model\Order $order,
        \Magento\Framework\DB\Transaction $transaction,
        \Magento\Sales\Model\Order\Shipment\Track $tracking,
        \Magento\Framework\HTTP\Client\Curl $curl
    )
    {
        $this->resultPageFactory = $resultPageFactory;
        $this->scopeConfig = $scopeConfig;
        $this->shipmentLoader = $shipmentLoader;
        $this->transportBuilder = $transportBuilder;
        $this->storeManager = $storeManager;
        $this->request = $context->getRequest();
        $this->resultJsonFactory = $resultJsonFactory;
        $this->helper = $helper;
        $this->order = $order;
        $this->transaction = $transaction;
        $this->tracking = $tracking;
        $this->curl = $curl;
        parent::__construct($context);
    }

    public function execute()
    {
        $post = $this->getRequest()->getPost();
        $order_id= $this->getRequest()->getParam('order_id');
        $result_redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);

        $order = $this->order->load($order_id);

        $error = $this->createShipment($order, $post);

        if (isset($error['error'])) {
            $this->_session->setData("ecoexpress_errors", true);

            $strip = strstr($post['ecoexpress_shipment_referer'], "eco_shipment_create_show", true);
            $url = $strip;
            if (empty($strip)) {
                $url = $post['ecoexpress_shipment_referer'];
            }
            $result_redirect->setUrl($url . 'eco_shipment_create_show/show');
            return $result_redirect;
        } else {
            $this->_session->setData("ecoexpress_errors", false);
            $result_redirect->setUrl($post['ecoexpress_shipment_referer']);
            return $result_redirect;
        }
    }

    private function _saveShipment($shipment)
    {
        $shipment->getOrder()->setIsInProcess(true);
        $this->transaction->addObject(
            $shipment
        )->addObject(
            $shipment->getOrder()
        )->save();
    }

    private function sendEmail($post, $order, $response)
    {
        if ($post['ecoexpress_email_customer'] == 'yes') {

            $storeId = $order->getStore()->getId();
            $copyTo = $this->helper->getEmails(self:: XML_PATH_SHIPMENT_EMAIL_COPY_TO, $storeId);
            $copyMethod = $this->scopeConfig->getValue(
                self::XML_PATH_SHIPMENT_EMAIL_COPY_METHOD,
                \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
                $storeId
            );
            $templateId = $this->scopeConfig->getValue(
                self::XML_PATH_SHIPMENT_EMAIL_TEMPLATE,
                \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
                $storeId
            );

            if ($order->getCustomerIsGuest()) {
                $customerName = $order->getBillingAddress()->getName();
            } else {
                $customerName = $order->getCustomerName();
            }

            $tracking_no = $response['body']['data']['tracking_no'];
            $templateParams = [
            'order' => $order,
            'customerName' => $customerName,
            'tracking_no' => $tracking_no
            ];
            $senderName = $this->scopeConfig->getValue(self::XML_PATH_TRANS_IDENTITY_NAME);
            $senderEmail = $this->scopeConfig->getValue(self::XML_PATH_TRANS_IDENTITY_EMAIL);

            if ($copyTo == "") {
                $transport = $this->transportBuilder->setTemplateIdentifier($templateId)
                ->setTemplateOptions(['area' => \Magento\Framework\App\Area::AREA_FRONTEND,
                   'store' => $storeId])
                ->setTemplateVars($templateParams)
                ->setFrom(['name' => $senderName, 'email' => $senderEmail])
                ->addTo($order->getCustomerEmail(), $customerName)
                ->getTransport();
            }

            if ($copyTo !== "" && $copyMethod == 'bcc') {
                $transport = $this->transportBuilder->setTemplateIdentifier($templateId)
                ->setTemplateOptions(['area' => \Magento\Framework\App\Area::AREA_FRONTEND,
                   'store' => $storeId])
                ->setTemplateVars($templateParams)
                ->setFrom(['name' => $senderName, 'email' => $senderEmail])
                ->addTo($order->getCustomerEmail(), $customerName)
                ->addBcc($copyTo)
                ->getTransport();
            }
            if ($copyTo !== "" && $copyMethod == 'copy') {
                $transport = $this->transportBuilder->setTemplateIdentifier($templateId)
                ->setTemplateOptions(['area' => \Magento\Framework\App\Area::AREA_FRONTEND,
                   'store' => $storeId])
                ->setTemplateVars($templateParams)
                ->setFrom(['name' => $senderName, 'email' => $senderEmail])
                ->addTo($order->getCustomerEmail(), $customerName)
                ->addBcc($copyTo)
                ->getTransport();
            }

            try {
                $transport->sendMessage();
            } catch (\Exception $ex) {
                $this->messageManager->addError($ex->getMessage());
            }
        }
    }

    private function createShipment($order, $post)
    {
        $accountInfo = $this->helper->getAccountInfo();

        $token = 'Bearer '.$accountInfo['APIToken'];

        $tot_qty = 0;
        foreach($post['ecoexpress_order_items'] as $ecoexpress_order_item){
          $tot_qty += $ecoexpress_order_item;
        }

        $post['quantity'] = $tot_qty;

        $headers = array(
          'Content-Type' => 'application/json',
          'Accept' => 'application/json',
		  'Content-Length' => strlen(json_encode($post)),
          'Authorization' => $token,
        );

        $url = "https://app.ecofreight.ae/api/webservices/client/magento/createawb";

        $this->curl->setHeaders($headers);

        $this->curl->post($url,json_encode($post));
        $response = $this->curl->getBody();

        if($this->curl->getStatus() == 200){
          $response = json_decode($response, TRUE);

          $tracking_no = $response['body']['data']['tracking_no'];

          $data = [
                  'items' => $post['ecoexpress_order_items'],
                  'comment_text' => "Eco Express Shipment AWB No. " . $tracking_no . " - Order No. " . $order->getId(),
                  'comment_customer_notify' => true,
                  'is_visible_on_front' => true
                ];

              $this->shipmentLoader->setOrderId($order->getId());
              $this->shipmentLoader->setShipmentId(null);
              $this->shipmentLoader->setShipment($data);
              $this->shipmentLoader->setTracking(null);
              $shipment = $this->shipmentLoader->load();

              if ($shipment) {
                  $track = $this->tracking->setNumber(
                      $tracking_no
                  )->setCarrierCode(
                      "ecoexpress"
                  )->setTitle(
                      "Eco Express"
                  );
                  $shipment->addTrack($track);
              }
              if (!$shipment) {
                  $this->_forward('noroute');
                  return;
              }
              if (!empty($data['comment_text'])) {
                  $shipment->addComment(
                      $data['comment_text'],
                      isset($data['comment_customer_notify']),
                      isset($data['is_visible_on_front'])
                  );

                  $shipment->setCustomerNote($data['comment_text']);
                  $shipment->setCustomerNoteNotify(isset($data['comment_customer_notify']));
              }
              $file = file_get_contents('https://app.ecofreight.ae/api/print-awb/'.$tracking_no.'/print');
              $pdf = new \Zend_Pdf($file);
              $shipment->setShippingLabel($pdf->render());

              $shipment->register();
              $this->_saveShipment($shipment);
              $this->sendEmail($post, $order, $response);
              $this->messageManager->addSuccess(
                  'Eco Express Shipment : ' . $tracking_no .
                          ' has been created.'
              );


        }else{
          $this->messageManager->addError('Eco Express - ' . $this->curl->getStatus() . ' - ' .
                  $response);
          return ['error' => true];
        }

    }

}
