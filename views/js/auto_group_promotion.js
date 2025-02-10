// Esperamos a que el documento esté listo
document.addEventListener('DOMContentLoaded', function () {
    console.log('Cargado el js')

    function setCookie(name, value, days) {
        let expires = '';
        if (days) {
            let date = new Date();
            date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
            expires = '; expires=' + date.toUTCString();
        }
        document.cookie = name + '=' + (value || '') + expires + '; path=/';
    }

    function getCookie(name) {
        let nameEQ = name + '=';
        let ca = document.cookie.split(';');
        for (let i = 0; i < ca.length; i++) {
            let c = ca[i];
            while (c.charAt(0) === ' ') c = c.substring(1, c.length);
            if (c.indexOf(nameEQ) === 0) return c.substring(nameEQ.length, c.length);
        }
        return null;
    }

    if(getCookie('auto_group_promotion_modal') !== null) {
        return;
    }
    
    // Comprobamos si el modal ya fue mostrado (utilizando cookies)
    if (typeof autoGroupPromotionModal !== 'undefined' && autoGroupPromotionModal) {
        if (!getCookie('auto_group_promotion_modal')) {
            // Creamos el modal
            let modal = document.createElement('div');
            modal.id = 'autoGroupPromotionModal';
            modal.innerHTML = `
                <div class="auto-group-promotion-overlay">
                    <div class="auto-group-promotion-modal">
                        <h2>¡Aprovecha nuestra promoción!</h2>
                        <p>Para beneficiarte de la promoción de ventiladores, necesitas alcanzar el mínimo de compra para portes gratuitos.</p>
                        <p>Haz tu compra y disfruta de los descuentos.</p>
                        <button id="closeModal">Cerrar</button>
                    </div>
                </div>
            `;
            document.body.appendChild(modal);

            // Estilos del modal
            let style = document.createElement('style');
            style.innerHTML = `
                #autoGroupPromotionModal {
                    display: flex;
                    justify-content: center;
                    align-items: center;
                    position: fixed;
                    top: 0;
                    left: 0;
                    right: 0;
                    bottom: 0;
                    z-index: 9999;
                    background-color: rgba(0, 0, 0, 0.7);
                }

                .auto-group-promotion-overlay {
                    display: flex;
                    justify-content: center;
                    align-items: center;
                    width: 100%;
                    height: 100%;
                }

                .auto-group-promotion-modal {
                    background-color: white;
                    padding: 20px;
                    border-radius: 8px;
                    text-align: center;
                    max-width: 400px;
                    width: 90%;
                    box-shadow: 0 0 10px rgba(0, 0, 0, 0.3);
                }

                .auto-group-promotion-modal h2 {
                    font-size: 24px;
                    margin-bottom: 15px;
                    color: #333;
                }

                .auto-group-promotion-modal p {
                    font-size: 16px;
                    margin-bottom: 20px;
                    color: #555;
                }

                .auto-group-promotion-modal button {
                    background-color:#000000;
                    color: white;
                    border: none;
                    padding: 10px 20px;
                    font-size: 16px;
                    border-radius: 4px;
                    cursor: pointer;
                    transition: background-color 0.3s;
                }

                .auto-group-promotion-modal button:hover {
                    background-color:rgb(255, 255, 255);
                    color: black;
                     box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2), 0 6px 20px rgba(0, 0, 0, 0.19);
                }

                /* Responsividad para móviles */
                @media (max-width: 600px) {
                    .auto-group-promotion-modal {
                        width: 90%;
                    }

                    .auto-group-promotion-modal h2 {
                        font-size: 20px;
                    }

                    .auto-group-promotion-modal p {
                        font-size: 14px;
                    }

                    .auto-group-promotion-modal button {
                        font-size: 14px;
                        padding: 8px 16px;
                    }
                }
            `;
            document.head.appendChild(style);

            // Función para cerrar el modal
            let closeModalButton = document.getElementById('closeModal');
            closeModalButton.addEventListener('click', function () {
                modal.style.display = 'none';
                // Marcamos que el modal ha sido mostrado usando cookies
                setCookie('auto_group_promotion_modal', '1', 1);
            });
        }
    }
});