<?php
namespace Ecoexpress\Carrier\Helper;


use Magento\Framework\App\Helper\AbstractHelper;

class Data extends AbstractHelper
{

    const SCOPE_STORE = \Magento\Store\Model\ScopeInterface::SCOPE_STORE;

    private $reader;

    private $request;

    private $scopeConfiguration;

    private $storeManager;

    private $store_id;

    public function __construct(
        \Magento\Backend\App\Action\Context $context,
        \Magento\Framework\Module\Dir\Reader $reader,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfiguration,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Sales\Api\OrderRepositoryInterface $orderRepository
    ) {
        $this->request = $context->getRequest();
        $this->storeManager = $storeManager;
        $this->reader = $reader;
        $this->scopeConfiguration = $scopeConfiguration;
        $this->store_id = $this->storeManager->getStore()->getId();
        $this->orderRepository = $orderRepository;
    }

    public function getAccountInfo()
    {

        $order_id = $this->request->getParam('order_id');

        if($order_id != null)
        {
            $order = $this->orderRepository->get($order_id);
        }

        if ($order_id && isset($order))
        {
            $store_id = (int) $order->getStoreId();
        }
        else
        {
             $store_id = $this->store_id;
        }

        $user_name = $this->scopeConfiguration->getValue(
            'ecoexpress/settings/user_name',
            self::SCOPE_STORE,
            $store_id
        );

        $api_token = $this->scopeConfiguration->getValue(
            'ecoexpress/settings/api_token',
            self::SCOPE_STORE,
            $store_id
        );
        return [
            'UserName' => $user_name,
            'APIToken' => $api_token,
        ];
    }

    public function getEmails($configPath, $store_id)
    {
        $data = $this->scopeConfiguration->getValue(
            $configPath,
            self::SCOPE_STORE,
            $this->store_id
        );
        if (!empty($data)) {
            return explode(',', $data);
        }
        return false;
    }

    public function getConfig($config_path)
    {
        return $this->scopeConfiguration->getValue(
            $config_path,
            self::SCOPE_STORE,
            $this->store_id
        );
    }

    public function get($config_path)
    {
        return $this->scopeConfiguration->getValue(
            $config_path,
            self::SCOPE_STORE,
            $this->store_id
        );
    }

    public function getCode()
    {
        return 'ecoexpress';
    }

}
