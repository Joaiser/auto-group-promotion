<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

class Auto_Group_Promotion extends Module
{
    // 📌 Definir constantes
    const GROUP_PROMOCION_PENINSULA_8 = 57;  
    const GROUP_PROMOCION_PENINSULA_16 = 51;  
    const GROUP_PROMOCION_CANARIAS_8 = 55;  
    const GROUP_PROMOCION_CANARIAS_16 = 58;   
    const PORTES_CANARIAS = 600; 
    const PORTES_GENERALES = 300; 

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
        return parent::install()
            && $this->registerHook('actionCarrierProcess')
            && $this->registerHook('actionValidateOrder')
            && $this->registerHook('actionDispatcherBefore')
            && $this->createTable();
    }

    public function reset()
{
    return $this->uninstall() && $this->install();
}


    public function uninstall()
    {
        return parent::uninstall() && $this->deleteTable();
    }

    private function createTable()
    {
        $sql = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'customer_removed_group` (
            `id_customer` INT(10) UNSIGNED NOT NULL,
            `id_group` INT(10) UNSIGNED NOT NULL,
            PRIMARY KEY (`id_customer`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8;';

        return Db::getInstance()->execute($sql);
    }

    private function deleteTable()
    {
        $sql = 'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'customer_removed_group`;';
        return Db::getInstance()->execute($sql);
    }

    private function clienteEnPromocion($id_cliente)
    {
        $sql = 'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'promotion_modal_log` WHERE id_customer = ' . (int)$id_cliente;
        return (bool)Db::getInstance()->getValue($sql);
    }

    private function obtenerMinimoPortes($id_cliente)
    {
        $customer = new Customer((int)$id_cliente);
        if (!Validate::isLoadedObject($customer)) {
            return self::PORTES_GENERALES;
        }

        return in_array($customer->id_default_group, [self::GROUP_PROMOCION_CANARIAS_8, self::GROUP_PROMOCION_CANARIAS_16])
            ? self::PORTES_CANARIAS
            : self::PORTES_GENERALES;
    }

    private function guardarGrupoEliminado($id_cliente, $id_grupo)
    {
        $sql = 'REPLACE INTO `' . _DB_PREFIX_ . 'customer_removed_group` (id_customer, id_group) 
                VALUES (' . (int)$id_cliente . ', ' . (int)$id_grupo . ')';
        Db::getInstance()->execute($sql);
    }

    private function obtenerUltimoGrupoEliminado($id_cliente)
    {
        $sql = 'SELECT id_group FROM `' . _DB_PREFIX_ . 'customer_removed_group` 
                WHERE id_customer = ' . (int)$id_cliente;
        return Db::getInstance()->getValue($sql);
    }

    private function eliminarGrupoGuardado($id_cliente)
    {
        $sql = 'DELETE FROM `' . _DB_PREFIX_ . 'customer_removed_group` WHERE id_customer = ' . (int)$id_cliente;
        Db::getInstance()->execute($sql);
    }

    public function hookActionCarrierProcess($params)
    {
       //Cookie para que se ejecute este hook y no otros
        $context = Context::getContext();
        $context->cookie->__set('hook_action_carrier_process', true);
        $context->cookie->write();

        $context = Context::getContext();
        $cart = $context->cart;
        $id_cliente = $context->customer->id;

        if (!$this->clienteEnPromocion($id_cliente)) {
            return;
        }

        $total_pedido = $cart->getOrderTotal(true, Cart::BOTH);
        $portes = $this->obtenerMinimoPortes($id_cliente);

        if ($total_pedido < $portes) {
            Media::addJsDef(['autoGroupPromotionModal' => true]);
            $this->context->controller->addJS($this->_path . 'views/js/auto_group_promotion.js');

            $grupos_promocion = [
                self::GROUP_PROMOCION_PENINSULA_8,
                self::GROUP_PROMOCION_PENINSULA_16,
                self::GROUP_PROMOCION_CANARIAS_8,
                self::GROUP_PROMOCION_CANARIAS_16
            ];

            foreach ($grupos_promocion as $grupo) {
                $sql = 'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'customer_group` 
                        WHERE id_customer = ' . (int)$id_cliente . ' 
                        AND id_group = ' . (int)$grupo;

                if (Db::getInstance()->getValue($sql)) {
                    $this->guardarGrupoEliminado($id_cliente, $grupo);

                    $sql = 'DELETE FROM `' . _DB_PREFIX_ . 'customer_group` 
                            WHERE id_customer = ' . (int)$id_cliente . ' 
                            AND id_group = ' . (int)$grupo;
                    Db::getInstance()->execute($sql);
                    break; // Eliminamos solo un grupo, no todos.
                }
            }
        }

         
    }


    public function hookActionValidateOrder($params)
    {
        $id_cliente = $params['order']->id_customer;
        $grupo_restaurar = $this->obtenerUltimoGrupoEliminado($id_cliente);
    
        if ($grupo_restaurar) {
            $sql = 'INSERT INTO `' . _DB_PREFIX_ . 'customer_group` (id_customer, id_group) 
                    VALUES (' . (int)$id_cliente . ', ' . (int)$grupo_restaurar . ')';
            Db::getInstance()->execute($sql);
    
            $this->eliminarGrupoGuardado($id_cliente); // Eliminamos de la tabla de eliminados
        }
    
        // Asegúrate de que el archivo JS se cargue correctamente
        PrestaShopLogger::addLog('Añadiendo JS para borrar localStorage.');
        $this->context->controller->addJS($this->_path . 'views/js/borrarLocalStorage.js');
    }
    

    public function hookActionDispatcherBefore($params)
{
    $context = Context::getContext();
    $id_cliente = $context->customer->id;

    // Si el cliente no está logueado, salimos
    if (!$id_cliente) {
        return;
    }

    // Revisar si hookActionCarrierProcess se ejecutó
    if (!empty($context->cookie->__get('hook_action_carrier_process'))) {
        // Eliminar la cookie personalizada
        $context->cookie->__unset('hook_action_carrier_process');
        return;
    }

    // Obtenemos el nombre del controlador usando Tools::getValue('controller')
    $controller_name = Tools::getValue('controller');
    PrestaShopLogger::addLog('Controller: ' . $controller_name);  // Log para verificar el controlador actual

    // Verificamos si estamos en la página de pago/checkout (order, orderconfirmation, etc.)
    $paginas_pago = ['order', 'orderconfirmation', 'ajax'];  // Añadimos 'order' aquí
    if (in_array($controller_name, $paginas_pago)) {
        PrestaShopLogger::addLog('Estamos en una página de pago/checkout, no restauramos el grupo.');
        return; // No restauramos el grupo si estamos en una página de pago o checkout
    }

    // Si el cliente abandona el checkout, restauramos su grupo
    $grupo_restaurar = $this->obtenerUltimoGrupoEliminado($id_cliente);
    if ($grupo_restaurar) {
        Db::getInstance()->execute(
            'INSERT INTO `' . _DB_PREFIX_ . 'customer_group` (id_customer, id_group) 
            VALUES (' . (int)$id_cliente . ', ' . (int)$grupo_restaurar . ')'
        );

        // Eliminamos el registro temporal
        $this->eliminarGrupoGuardado($id_cliente);
    }
}

}
