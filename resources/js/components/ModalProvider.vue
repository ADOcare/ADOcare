<script setup lang="ts">
import { closeModal, modalState } from '@/composables/useModal';
import Dialog from 'primevue/dialog';

</script>

<template>
    <div>
        <template v-for="modal in modalState.modals" :key="modal.id">
            <!-- PrimeVue Dialog wraps the dynamic component -->
            <Dialog
                :visible="modal.visible"
                :header="modal.dialogOptions.header ?? modal.props.title ?? ' '"
                :modal="modal.dialogOptions.modal ?? true"
                :closable="modal.dialogOptions.closable ?? true"
                :dismissable-mask="modal.dialogOptions.dismissableMask ?? true"
                :style="modal.dialogOptions.style"
                :class="modal.dialogOptions.class"
                @hide="() => closeModal(modal.id)"
                @update:visible="(val: boolean) => { if (!val) closeModal(modal.id) }"
                >
                <component
                    :is="modal.component"
                    v-bind="{ ...modal.props, visible: modal.visible, modalResolve: (res: any) => closeModal(modal.id, res) }"
                    @update:visible="(val: boolean) => { if (!val) closeModal(modal.id) }"
                />
            </Dialog>
        </template>
    </div>
</template>

<style scoped></style>
