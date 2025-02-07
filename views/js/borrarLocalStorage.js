document.addEventListener('DOMContentLoaded', function () {
    if (typeof(Storage) !== "undefined") {
        console.log("localStorage disponible");
        localStorage.removeItem('auto_group_promotion_modal');
        console.log("Item eliminado de localStorage");
    } else {
        console.log("localStorage no está disponible");
    }
});