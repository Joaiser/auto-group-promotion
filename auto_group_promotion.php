<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

class Auto_Group_Promotion extends Module
{
    // 📌 1️⃣ - Definir constantes
    const GROUP_PROMOCION_PENINSULA_8 = 57;  
    const GROUP_PROMOCION_PENINSULA_16 = 51;  
    const GROUP_PROMOCION_CANARIAS_8 = 55;  
    const GROUP_PROMOCION_CANARIAS_16 = 58;  
    const GROUP_PENINSULA = 3;  
    const GROUP_CANARIAS = 33;  
    const PORTES_CANARIAS = 600; 
    const PORTES_GENERALES = 300; 
    private $grupo_original = [];

    public function __construct()
    {
        $this->name = 'auto_group_promotion';
        $this->tab = 'administration';
        $this->version = '1.0.0';
        $this->author = 'Aitor';
        $this->need_instance = 0;

        parent::__construct();

        $this->displayName = $this->l('Cambio automático de grupo por promoción');
        $this->description = $this->l('Cambia automáticamente el grupo de clientes cuando cumplen las condiciones de la promoción.');

        $this->ps_versions_compliancy = ['min' => '1.7', 'max' => _PS_VERSION_];
    }

    public function install()
    {
        try {
            return parent::install()
                && $this->registerHook('actionCarrierProcess')
                && $this->registerHook('actionValidateOrder');
        } catch (Exception $e) {
            PrestaShopLogger::addLog("Error al instalar el módulo: " . $e->getMessage(), 3);
            return false;
        }
    }

    public function uninstall()
    {
        try {
            return parent::uninstall();
        } catch (Exception $e) {
            PrestaShopLogger::addLog("Error al desinstalar el módulo: " . $e->getMessage(), 3);
            return false;
        }
    }

    private function clienteEnPromocion($id_cliente)
    {
        try {
            $sql = 'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'promotion_modal_log` WHERE id_customer = ' . (int)$id_cliente;
            return (bool)Db::getInstance()->getValue($sql);
        } catch (Exception $e) {
            PrestaShopLogger::addLog("Error al verificar cliente en promoción: " . $e->getMessage(), 3);
            return false;
        }
    }

    private function obtenerMinimoPortes($id_cliente)
    {
        try {
            $customer = new Customer((int)$id_cliente);
            if (!Validate::isLoadedObject($customer)) {
                return self::PORTES_GENERALES;
            }

            $id_default_group = $customer->id_default_group;
            $esCanarias = in_array($id_default_group, [self::GROUP_PROMOCION_CANARIAS_8, self::GROUP_PROMOCION_CANARIAS_16]);

            return $esCanarias ? self::PORTES_CANARIAS : self::PORTES_GENERALES;
        } catch (Exception $e) {
            PrestaShopLogger::addLog("Error al obtener mínimo de portes: " . $e->getMessage(), 3);
            return self::PORTES_GENERALES;
        }
    }

    private function cambiarGrupoCliente($id_cliente, $id_grupo)
    {
        try {
            $customer = new Customer((int)$id_cliente);
            if (Validate::isLoadedObject($customer)) {
                $this->asegurarGrupoOriginal($id_cliente);
                $customer->id_default_group = (int)$id_grupo;
                $customer->update();
            }
        } catch (Exception $e) {
            PrestaShopLogger::addLog("Error al cambiar grupo al cliente: " . $e->getMessage(), 3);
        }
    }

    private function asegurarGrupoOriginal($id_cliente)
    {
        try {
            if (!isset($this->grupo_original[$id_cliente])) {
                $customer = new Customer((int)$id_cliente);
                if (Validate::isLoadedObject($customer)) {
                    $this->grupo_original[$id_cliente] = $customer->id_default_group;
                }
            }
        } catch (Exception $e) {
            PrestaShopLogger::addLog("Error al asegurar el grupo original: " . $e->getMessage(), 3);
        }
    }

    private function mostrarModal()
    {
        try {
            Media::addJsDef(['autoGroupPromotionModal' => true]);
        } catch (Exception $e) {
            PrestaShopLogger::addLog("Error al mostrar el modal: " . $e->getMessage(), 3);
        }
    }

    public function hookActionCarrierProcess($params)
    {
        try {
            $context = Context::getContext();
            $cart = $context->cart;
            $id_cliente = $context->customer->id;

            if (!$this->clienteEnPromocion($id_cliente)) {
                return;
            }

            $this->asegurarGrupoOriginal($id_cliente);

            $total_pedido = $cart->getOrderTotal(true, Cart::BOTH);
            $portes = $this->obtenerMinimoPortes($id_cliente);

            if ($total_pedido < $portes) {
                $this->mostrarModal();
                $this->context->controller->addJS($this->_path . 'views/js/auto_group_promotion.js');

                $id_default_group = $context->customer->id_default_group;

                if (in_array($id_default_group, [self::GROUP_PROMOCION_CANARIAS_8, self::GROUP_PROMOCION_CANARIAS_16])) {
                    $this->cambiarGrupoCliente($id_cliente, self::GROUP_CANARIAS);
                } else {
                    $this->cambiarGrupoCliente($id_cliente, self::GROUP_PENINSULA);
                }
            }
        } catch (Exception $e) {
            PrestaShopLogger::addLog("Error en actionCarrierProcess: " . $e->getMessage(), 3);
        }
    }

    public function hookActionValidateOrder($params)
    {
        try {
            $order = $params['order'];
            $id_cliente = $order->id_customer;
            $customer = new Customer((int)$id_cliente);

            $this->asegurarGrupoOriginal($id_cliente);

            if (in_array($customer->id_default_group, [
                self::GROUP_PROMOCION_PENINSULA_8, self::GROUP_PROMOCION_PENINSULA_16,
                self::GROUP_PROMOCION_CANARIAS_8, self::GROUP_PROMOCION_CANARIAS_16
            ])) {
                $customer->id_default_group = $this->grupo_original[$id_cliente] ?? self::GROUP_PENINSULA;
                $customer->update();
            }
        } catch (Exception $e) {
            PrestaShopLogger::addLog("Error en actionValidateOrder: " . $e->getMessage(), 3);
        }
    }
}

//HAY QUE HACER PARA CUANDO UN CLIENTE SALGA DE LA PÁGINA DE PAGO
//SI NO HA HECHO EL PEDIDO PARA QUE VUELVA AL GRUPO ORIGINAL