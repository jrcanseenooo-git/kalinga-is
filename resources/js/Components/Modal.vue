<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';

const props = withDefaults(
    defineProps<{
        show?: boolean;
        maxWidth?: 'sm' | 'md' | 'lg' | 'xl' | '2xl';
        closeable?: boolean;
    }>(),
    {
        show: false,
        maxWidth: '2xl',
        closeable: true,
    },
);

const emit = defineEmits(['close', 'closed']);
const dialog = ref();
const showSlot = ref(props.show);

watch(
    () => props.show,
    () => {
        if (props.show) {
            document.body.style.overflow = 'hidden';
            showSlot.value = true;

            dialog.value?.showModal();
        } else {
            document.body.style.overflow = '';

            setTimeout(() => {
                dialog.value?.close();
                showSlot.value = false;
                emit('closed');
            }, 200);
        }
    },
);

const close = () => {
    if (props.closeable) {
        emit('close');
    }
};

const closeOnEscape = (e: KeyboardEvent) => {
    if (e.key === 'Escape') {
        e.preventDefault();

        if (props.show) {
            close();
        }
    }
};

onMounted(() => document.addEventListener('keydown', closeOnEscape));

onUnmounted(() => {
    document.removeEventListener('keydown', closeOnEscape);

    document.body.style.overflow = '';
});

const maxWidthClass = computed(() => {
    return {
        sm: 'sm:max-w-sm',
        md: 'sm:max-w-md',
        lg: 'sm:max-w-lg',
        xl: 'sm:max-w-xl',
        '2xl': 'sm:max-w-2xl',
    }[props.maxWidth];
});
</script>

<template>
    <!-- Phones: a bottom sheet that scrolls inside itself. Larger screens: a centred dialog. -->
    <dialog
        class="z-50 m-0 min-h-full min-w-full overflow-y-auto bg-transparent backdrop:bg-transparent"
        ref="dialog"
    >
        <div class="fixed inset-0 z-50 overflow-y-auto" scroll-region>
            <Transition
                enter-active-class="ease-out duration-200"
                enter-from-class="opacity-0"
                enter-to-class="opacity-100"
                leave-active-class="ease-in duration-150"
                leave-from-class="opacity-100"
                leave-to-class="opacity-0"
            >
                <div v-show="show" class="fixed inset-0 bg-slate-900/45 backdrop-blur-[1px]" aria-hidden="true" @click="close" />
            </Transition>

            <div class="pointer-events-none flex min-h-full items-end justify-center sm:items-center sm:px-4 sm:py-8">
                <Transition
                    enter-active-class="ease-out duration-200"
                    enter-from-class="opacity-0 translate-y-8 sm:translate-y-0 sm:scale-95"
                    enter-to-class="opacity-100 translate-y-0 sm:scale-100"
                    leave-active-class="ease-in duration-150"
                    leave-from-class="opacity-100 translate-y-0 sm:scale-100"
                    leave-to-class="opacity-0 translate-y-8 sm:translate-y-0 sm:scale-95"
                >
                    <div
                        v-show="show"
                        class="modal-panel pointer-events-auto relative z-10 w-full transform overflow-y-auto rounded-t-2xl bg-white shadow-2xl transition-all sm:rounded-2xl"
                        :class="maxWidthClass"
                    >
                        <span class="mx-auto mt-2 block h-1 w-10 rounded-full bg-slate-300 sm:hidden" aria-hidden="true" />
                        <slot v-if="showSlot" />
                    </div>
                </Transition>
            </div>
        </div>
    </dialog>
</template>

<style scoped>
.modal-panel { max-height: 92dvh; padding-bottom: env(safe-area-inset-bottom); }
@media (min-width: 640px) { .modal-panel { max-height: 88dvh; } }
</style>
