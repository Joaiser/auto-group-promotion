// Esperamos a que el documento esté listo
document.addEventListener('DOMContentLoaded', function () {
    console.log('Cargado el js para quitar localStorage')

    // Restablecemos el valor en localStorage para permitir que el modal se muestre de nuevo
    localStorage.removeItem('auto_group_promotion_modal');

});
