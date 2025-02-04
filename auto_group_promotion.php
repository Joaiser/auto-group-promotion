<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

class Auto_Group_Promotion extends Module
{
    // 📌 1️⃣ - Definir constantes a nivel de la clase
    const GROUP_PROMOCION_PENINSULA_8 = 57;  // ID del grupo "Promoción ventiladores surtido 8 península"
    const GROUP_PROMOCION_PENINSULA_16 = 51;  // ID del grupo "Promoción ventiladores surtido 16 península"
    const GROUP_PROMOCION_CANARIAS_8 = 55;  // ID del grupo "Promoción ventiladores surtido 8 Canarias"
    const GROUP_PROMOCION_CANARIAS_16 = 58;  // ID del grupo "Promoción ventiladores surtido 16 Canarias"
    const PORTES_CANARIAS = 600; // Precio mínimo para portes gratis en Canarias
    const PORTES_GENERALES = 300; // Precio mínimo para portes gratis en península
    private $grupo_original = [];

    public function __construct()
    {
        // 📌 2️⃣ - Configuración del módulo
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
        // 📌 3️⃣ - Registra los hooks clave para la funcionalidad
        try {
            return parent::install()
                && $this->registerHook('displayBeforeCarrier')  // Hook 1: Antes de seleccionar transportista
                && $this->registerHook('actionValidateOrder');  // Hook 2: Al validar el pedido
        } catch (Exception $e) {
            // Captura cualquier error durante la instalación y muestra un mensaje
            PrestaShopLogger::addLog("Error al instalar el módulo Auto_Group_Promotion: " . $e->getMessage(), 3);
            return false;
        }
    }

    public function uninstall()
    {
        try {
            return parent::uninstall();
        } catch (Exception $e) {
            // Captura cualquier error durante la desinstalación y muestra un mensaje
            PrestaShopLogger::addLog("Error al desinstalar el módulo Auto_Group_Promotion: " . $e->getMessage(), 3);
            return false;
        }
    }

    // 📌 4️⃣ - Métodos auxiliares para la lógica de negocio

    /**
     * Verifica si el cliente está en la tabla de promoción
     */
    private function clienteEnPromocion($id_cliente)
    {
        try {
            $sql = 'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'promotion_modal_log` WHERE id_customer = ' . (int)$id_cliente;
            return (bool)Db::getInstance()->getValue($sql);
        } catch (Exception $e) {
            // Si hay error en la consulta, loguea el error
            PrestaShopLogger::addLog("Error al verificar cliente en promoción: " . $e->getMessage(), 3);
            return false;
        }
    }

    /**
     * Obtiene el mínimo de portes según si el cliente es de Canarias o no
     */
    private function obtenerMinimoPortes($id_cliente)
    {
        try {
            $address = new Address((int)Customer::getDefaultAddressId((int)$id_cliente));
            if (!$address || empty($address->id_country)) {
                return Configuration::get('PORTES_GENERALES'); // Usar el valor de configuración
            }

            $esCanarias = in_array(substr($address->postcode, 0, 2), ['35', '38']);
            return $esCanarias ? Configuration::get('PORTES_CANARIAS') : Configuration::get('PORTES_GENERALES');
        } catch (Exception $e) {
            // Si hay error al obtener la dirección, loguea el error
            PrestaShopLogger::addLog("Error al obtener mínimo de portes: " . $e->getMessage(), 3);
            return Configuration::get('PORTES_GENERALES'); // Valor por defecto
        }
    }

    /**
     * Cambia el grupo del cliente a otro temporalmente
     */
    private function cambiarGrupoCliente($id_cliente, $id_grupo)
    {
        try {
            $customer = new Customer((int)$id_cliente);
            if (Validate::isLoadedObject($customer)) {
                // Asegurarse de que el grupo original esté guardado
                $this->asegurarGrupoOriginal($id_cliente);

                // Cambiar el grupo
                $customer->id_default_group = (int)$id_grupo;
                $customer->update();
            }
        } catch (Exception $e) {
            // Loguea el error si falla el cambio de grupo
            PrestaShopLogger::addLog("Error al cambiar grupo al cliente: " . $e->getMessage(), 3);
        }
    }

    /**
     * Obtiene el grupo original del cliente antes de cambiarlo
     */
    private function getGrupoOriginal($id_cliente)
    {
        try {
            // Asegurarse de que el grupo original esté guardado
            $this->asegurarGrupoOriginal($id_cliente);

            return isset($this->grupo_original[$id_cliente]) ? $this->grupo_original[$id_cliente] : self::GROUP_CANARIAS; // Default
        } catch (Exception $e) {
            // Loguea el error y devuelve un valor por defecto
            PrestaShopLogger::addLog("Error al obtener grupo original: " . $e->getMessage(), 3);
            return self::GROUP_CANARIAS;
        }
    }

    /**
     * Guarda el grupo original del cliente si no está guardado
     */
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
            // Loguea cualquier error
            PrestaShopLogger::addLog("Error al asegurar el grupo original: " . $e->getMessage(), 3);
        }
    }

    /**
     * Muestra un modal de aviso con JavaScript
     */
    private function mostrarModal()
    {
        try {
            Media::addJsDef([
                'autoGroupPromotionModal' => true
            ]);
        } catch (Exception $e) {
            // Loguea el error si falla al mostrar el modal
            PrestaShopLogger::addLog("Error al mostrar el modal: " . $e->getMessage(), 3);
        }
    }

    // 📌 5️⃣ - Hooks principales que controlan el flujo

    /**
     * Hook que se ejecuta antes de seleccionar el transportista
     * - Verifica si el cliente está en la promoción
     * - Evalúa si el total del pedido cumple con el mínimo para portes gratis
     * - Si no cumple, muestra un modal y cambia temporalmente el grupo del cliente
     */
    public function hookDisplayBeforeCarrier($params)
    {
        try {
            $context = Context::getContext();
            $cart = $context->cart;
            $id_cliente = $context->customer->id;

            if (!$this->clienteEnPromocion($id_cliente)) {
                return;
            }

            // Asegurarse de que el grupo original esté guardado
            $this->asegurarGrupoOriginal($id_cliente);

            $total_pedido = $cart->getOrderTotal(true, Cart::BOTH);
            $portes = $this->obtenerMinimoPortes($id_cliente);

            if ($total_pedido < $portes) {
                $this->mostrarModal();
                // Agregar el archivo JS
                $this->context->controller->addJS($this->_path . 'views/js/auto_group_promotion.js');

                // Determinar si el cliente es canario según su grupo
                $id_default_group = $context->customer->id_default_group;

                if (in_array($id_default_group, [self::GROUP_PROMOCION_CANARIAS_8, self::GROUP_PROMOCION_CANARIAS_16])) {
                    // Si el cliente está en un grupo de Canarias, cambiar al grupo correspondiente a Canarias
                    $this->cambiarGrupoCliente($id_cliente, self::GROUP_PROMOCION_CANARIAS_8);
                } else {
                    // Si es peninsular, asignar grupo de promoción peninsular
                    $this->cambiarGrupoCliente($id_cliente, self::GROUP_PROMOCION_PENINSULA_8);
                }
            }
        } catch (Exception $e) {
            // Loguea el error
            PrestaShopLogger::addLog("Error en el hook displayBeforeCarrier: " . $e->getMessage(), 3);
        }
    }

    /**
     * Hook que se ejecuta tras validar el pedido
     * - Si el cliente estaba en un grupo promocional, lo devuelve a su grupo original
     */
    public function hookActionValidateOrder($params)
    {
        try {
            $order = $params['order'];
            $id_cliente = $order->id_customer;
            $customer = new Customer((int)$id_cliente);

            // Asegurarse de que el grupo original esté guardado
            $this->asegurarGrupoOriginal($id_cliente);

            if (in_array($customer->id_default_group, [self::GROUP_PROMOCION_PENINSULA_8, self::GROUP_PROMOCION_PENINSULA_16, self::GROUP_PROMOCION_CANARIAS_8, self::GROUP_PROMOCION_CANARIAS_16])) {
                $customer->id_default_group = $this->getGrupoOriginal($id_cliente);
                $customer->update();
            }
        } catch (Exception $e) {
            // Loguea el error
            PrestaShopLogger::addLog("Error en el hook actionValidateOrder: " . $e->getMessage(), 3);
        }
    }
}
