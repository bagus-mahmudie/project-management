import { onBeforeUnmount, onMounted } from 'vue';

const popupOpenEvent = 'app:exclusive-popup-open';

export function useExclusivePopup(closePopup) {
    const popupId = Symbol('exclusive-popup');

    function handlePopupOpen(event) {
        if (event.detail?.popupId !== popupId) {
            closePopup();
        }
    }

    function openExclusivePopup() {
        document.dispatchEvent(
            new CustomEvent(popupOpenEvent, {
                detail: { popupId },
            }),
        );
    }

    onMounted(() => {
        document.addEventListener(popupOpenEvent, handlePopupOpen);
    });

    onBeforeUnmount(() => {
        document.removeEventListener(popupOpenEvent, handlePopupOpen);
    });

    return { openExclusivePopup };
}
