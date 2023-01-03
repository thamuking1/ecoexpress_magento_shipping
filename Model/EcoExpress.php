<?php
namespace Ecoexpress\Carrier\Model;

use Magento\Quote\Model\Quote\Address\RateRequest;
use Magento\Shipping\Model\Carrier\AbstractCarrierOnline;
use Magento\Shipping\Model\Carrier\CarrierInterface;
use Magento\Framework\Xml\Security;
use \Magento\Customer\Model\Session;

class EcoExpress extends AbstractCarrierOnline implements CarrierInterface
{
    private $servicetypes;

    const SCOPE_STORE = \Magento\Store\Model\ScopeInterface::SCOPE_STORE;

    private $request;

    private $result;

    private $errors = [];

    private $helper;

    private $storeManager;

    private $customer;

    private $country;

    private $sessionCustomer;

    private $objectFactory;
    private $storeId;

    private $curl;

    protected $_rateResultFactory;
    protected $_rateMethodFactory;
    protected $_code;

    public function __construct(
        \Magento\Shipping\Model\Rate\ResultFactory $rateResultFactory,
        \Magento\Quote\Model\Quote\Address\RateResult\MethodFactory $rateMethodFactory,
        \Ecoexpress\Carrier\Helper\Data $helper,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory $rateErrorFactory,
        \Psr\Log\LoggerInterface $logger,
        Security $xmlSecurity,
        \Magento\Shipping\Model\Simplexml\ElementFactory $xmlElFactory,
        \Magento\Shipping\Model\Rate\ResultFactory $rateFactory,
        \Magento\Shipping\Model\Tracking\ResultFactory $trackFactory,
        \Magento\Shipping\Model\Tracking\Result\ErrorFactory $trackErrorFactory,
        \Magento\Shipping\Model\Tracking\Result\StatusFactory $trackStatusFactory,
        \Magento\Directory\Model\RegionFactory $regionFactory,
        \Magento\Directory\Model\CountryFactory $countryFactory,
        \Magento\Directory\Model\CurrencyFactory $currencyFactory,
        \Magento\Directory\Helper\Data $directoryData,
        \Magento\CatalogInventory\Api\StockRegistryInterface $stockRegistry,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Ecoexpress\Carrier\Model\Carrier\Ecoexpress\Source\ServiceTypes $servicetypes,
        \Magento\Customer\Model\Customer $customer,
        \Magento\Directory\Model\Config\Source\Country $country,
        Session $sessionCustomer,
        \Magento\Framework\DataObjectFactory $objectFactory,
        \Magento\Framework\HTTP\Client\Curl $curl,
        array $data = []
    ) {
        $this->_rateResultFactory = $rateResultFactory;
        $this->_rateMethodFactory = $rateMethodFactory;
        $this->helper = $helper;
        $this->storeManager = $storeManager;
        $this->servicetypes = $servicetypes;
        $this->customer = $customer;
        $this->country = $country;
        $this->sessionCustomer= $sessionCustomer;
        $this->_code = $helper->getCode();
        $this->objectFactory = $objectFactory;
        $this->storeId = $this->storeManager->getStore()->getId();
        $this->curl = $curl;
         parent::__construct(
            $scopeConfig,
            $rateErrorFactory,
            $logger,
            $xmlSecurity,
            $xmlElFactory,
            $rateFactory,
            $rateMethodFactory,
            $trackFactory,
            $trackErrorFactory,
            $trackStatusFactory,
            $regionFactory,
            $countryFactory,
            $currencyFactory,
            $directoryData,
            $stockRegistry,
            $data
        );
    }

    public function getAllowedMethods()
    {
         return [$this->_code => $this->getConfigData('name') ];
    }

    public function collectRates(RateRequest $request)
    {
        $this->request = $request;
        if (!$this->getConfigFlag('active')) {
            return false;
        }

        $this->setRequest($request);
        return $this->result = $this->_getQuotes();
    }

    public function setRequest(RateRequest $request)
    {
        $this->request = $request;
        $r = $this->objectFactory->create();
        $r = $this->setAdditionalData($request, $r);
        $machinable = $this->getConfigData('machinable');

        $r->setMachinable($machinable);
        if ($request->getOrigPostcode()) {
            $r->setOrigPostal($request->getOrigPostcode());
        } else {
            $r->setOrigPostal($this->_scopeConfig->getValue('shipping/origin/postcode', self::SCOPE_STORE, $this->storeId) == 1);
        }

        if ($request->getDestCountryId()) {
            $destCountry = $request->getDestCountryId();
        } else {
            $destCountry = self::USA_COUNTRY_ID;
        }
        $r->setDestCountryId($destCountry);

        $countries = $this->country->toOptionArray();
        foreach ($countries as $country) {
            if ($country['value'] == $destCountry) {
                $r->setDestCountryName($country['label']);
            }
        }
        if ($request->getDestPostcode()) {
            $r->setDestPostal($request->getDestPostcode());
        }
        $weight = $this->getTotalNumOfBoxes($request->getPackageWeight());
        $r->setWeightPounds($weight);
        $r->setPackageQty($request->getPackageQty());
        $r->setWeightOunces(round(($weight - floor($weight)) * 16, 1));
        if ($request->getFreeMethodWeight() != $request->getPackageWeight()) {
            $r->setFreeMethodWeight($request->getFreeMethodWeight());
        }
        $r->setDestState($request->getDestRegionCode());
        $r->setValue($request->getPackageValue());
        $r->setValueWithDiscount($request->getPackageValueWithDiscount());
        $r->setDestCity($request->getDestCity());

        $this->_rawRequest = $r;

    }

    public function setAdditionalData($request, $r)
    {
        if ($request->getLimitMethod()) {
            $r->setService($request->getLimitMethod());
        } else {
            $r->setService('ALL');
        }

        $userId = $this->getConfigData('userid');
        $r->setUserId($userId);

        $container = $this->getConfigData('container');
        $r->setContainer($container);

        $size = $this->getConfigData('size');
        $r->setSize($size);

        return $r;
    }

    private function _getQuotes()
    {
        return $this->_getEcoExpressPrice();
    }

    public function _getEcoExpressPrice()
    {
        $r = $this->_rawRequest;
        $package_weight = $r->getWeightPounds();
        $package_qty = $r->getPackageQty();
        $service_location = 'DOM';
        $allowed_methods_key = 'allowed_service_types';
        $allowed_methods = $this->servicetypes->toKeyArray();
        $shipper_country = $this->_scopeConfig->getValue('ecoexpress/shipperdetail/shipper_country', self::SCOPE_STORE, $this->storeId);
        if ($this->_scopeConfig->getValue('ecoexpress/shipperdetail/shipper_country', self::SCOPE_STORE, $this->storeId) != $r->
            getDestCountryId()) {
            $service_location = 'INT';
            $allowed_methods = ["Outbound"];
            $allowed_methods_key = 'allowed_international_methods';
        }

		if($service_location == "DOM"){
        $admin_allowed_methods = explode(',', $this->_scopeConfig->getValue('ecoexpress/shipperdetail/shipper_country', self::SCOPE_STORE, $this->storeId));
        $admin_allowed_methods = array_flip($admin_allowed_methods);
        $allowed_methods = array_intersect_key($allowed_methods, $admin_allowed_methods);
		}

        $accountInfo = $this->helper->getAccountInfo();

        $priceArr = [];

        $result = $this->_rateResultFactory->create();

        foreach ($allowed_methods as $key => $value) {

          $post_data = array();
          $post_data['client_data'] = $accountInfo['UserName'];
          $post_data['rec_state'] = $r->getDestState();
          $post_data['rec_city'] = $r->getDestCity();
          $post_data['rec_postcode'] = self::USA_COUNTRY_ID == $r->getDestCountryId() ? substr($r->getDestPostal(), 0, 5) : $r->getDestPostal();
          $post_data['rec_country'] = $r->getDestCountryId();
          $post_data['service_location'] = $service_location;
          $post_data['service_type'] = $key;
          $post_data['weight'] = array("value" => $package_weight,"unit" => "KG");
          $post_data['qty'] = $package_qty;
          $token = 'Bearer '.$accountInfo['APIToken'];

          $headers = array(
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'Authorization' => $token,
          );

          $url = "https://app.ecofreight.ae/api/webservices/client/woocommerce/checkprice";

          $this->curl->setHeaders($headers);
          $this->curl->post($url,json_encode($post_data));
          $response = $this->curl->getBody();

          if($this->curl->getStatus() == 200 || $this->curl->getStatus() == 100){
            $response = json_decode($response, TRUE);

            $rate = $this->_rateMethodFactory->create();
            $rate->setCarrier($this->_code);
            $rate->setCarrierTitle($this->getConfigData('title'));
            $rate->setMethod($key);
            $rate->setMethodTitle("Eco Express ".$response['body']['service_type']);
            $rate->setPrice($response['body']['price']);
            $rate->setCost($response['body']['price']);
            $result->append($rate);
          }
        }

        return $result;
    }

    public function _doShipmentRequest(\Magento\Framework\DataObject $request)
    {
        $request = null;
    }

    public function proccessAdditionalValidation(\Magento\Framework\DataObject $request)
    {
        return true;
    }

    public function getTracking($trackings)
    {
        $this->setTrackingReqeust();
        if (!is_array($trackings)) {
            $trackings = [$trackings];
        }

        $this->_getXmlTracking($trackings);
        return $this->result;
    }

    private function setTrackingReqeust()
    {
        $r = $this->objectFactory->create();
        $userId = $this->getConfigData('userid');
        $r->setUserId($userId);
        $this->_rawTrackRequest = $r;
    }

    private function _getXmlTracking($trackings)
    {
        $r = $this->_rawTrackRequest;
        foreach ($trackings as $tracking) {
            $this->_parseXmlTrackingResponse($tracking);
        }
    }

    private function _parseXmlTrackingResponse($trackingvalue)
    {
        $resultArr = [];
        if (!$this->result) {
            $this->result = $this->_trackFactory->create();
        }

        $tracking = $this->_trackStatusFactory->create();
        $tracking->setCarrier('ecoexpress');
        $tracking->setCarrierTitle($this->getConfigData('title'));
        $tracking->setUrl('https://app.ecofreight.ae/tracking/' . $trackingvalue);
        $tracking->setTracking($trackingvalue);
        $this->result->append($tracking);
    }

}
