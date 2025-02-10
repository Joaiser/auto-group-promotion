<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

class Auto_Group_Promotion extends Module
{
    // 📌 Definir constantes

    const GROUP_PROMOCION_PENINSULA_8 = 48;  
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

    private function obtenerUltimoGrupoEliminadodePromotionModalLog($id_cliente)
    {
        $sql = 'SELECT last_group_default FROM `' . _DB_PREFIX_ . 'promotion_modal_log` 
                WHERE id_customer = ' . (int)$id_cliente;
        return Db::getInstance()->getValue($sql);
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
        // Cookie para que se ejecute este hook y no otros
        $context = Context::getContext();
        $context->cookie->__set('hook_action_carrier_process', true);
        $context->cookie->write();
    
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
                    // Guardar el grupo eliminado en customer_removed_group
                    $this->guardarGrupoEliminado($id_cliente, $grupo);
    
                    // Obtener el último grupo por defecto desde promotion_modal_log
                    $ultimo_grupo = $this->obtenerUltimoGrupoEliminadodePromotionModalLog($id_cliente);
    
                    // Si existe un grupo anterior, actualizar id_default_group con este
                    if ($ultimo_grupo) {
                        $sql = 'UPDATE `' . _DB_PREFIX_ . 'customer` 
                                SET id_default_group = ' . (int)$ultimo_grupo . '
                                WHERE id_customer = ' . (int)$id_cliente;
                        Db::getInstance()->execute($sql);
                    }
    
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
        // Restaurar el grupo en ps_customer (actualizar id_default_group)
        $sql = 'UPDATE `' . _DB_PREFIX_ . 'customer` 
                SET id_default_group = ' . (int)$grupo_restaurar . ' 
                WHERE id_customer = ' . (int)$id_cliente;
        Db::getInstance()->execute($sql);

        // Eliminar el registro de grupo eliminado
        $this->eliminarGrupoGuardado($id_cliente);
    }

    // Borrar la cookie
    if (isset($_COOKIE['auto_group_promotion_modal'])) {
        setcookie('auto_group_promotion_modal', '', time() - 3600, '/');
        PrestaShopLogger::addLog('Cookie auto_group_promotion_modal eliminada.');
    }
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

    // Verificamos si estamos en la página de pago/checkout
    $controller_name = Tools::getValue('controller');
    $paginas_pago = ['order', 'orderconfirmation', 'ajax'];  // Añadimos 'order' aquí
    if (in_array($controller_name, $paginas_pago)) {
        return; // No restauramos el grupo si estamos en una página de pago o checkout
    }

    // Restaurar el grupo si el cliente abandona el checkout
    $grupo_restaurar = $this->obtenerUltimoGrupoEliminado($id_cliente);
    if ($grupo_restaurar) {
        // Restauramos el grupo en ps_customer (id_default_group)
        Db::getInstance()->execute(
            'UPDATE `' . _DB_PREFIX_ . 'customer` 
            SET id_default_group = ' . (int)$grupo_restaurar . ' 
            WHERE id_customer = ' . (int)$id_cliente
        );

        // Eliminar el registro temporal
        $this->eliminarGrupoGuardado($id_cliente);
    }
}


}
