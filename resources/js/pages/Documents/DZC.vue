<script setup lang="ts">
import {
    computed,
    ref,
} from 'vue'

import {
    useRoute,
} from 'vue-router'

import api from '@/services/api'

import {
    usePublicDocument,
    type PublicDocumentProps,
} from '@/composables/usePublicDocument'

import AppButton from '@/components/AppButton.vue'
import DocumentShell from '@/components/DocumentShell.vue'
import FormField from '@/components/FormField.vue'

const props =
    defineProps<
        PublicDocumentProps
    >()

const route =
    useRoute()

const {
    previewUrl,
    getPublicLink,
    documentId,
} =
    usePublicDocument(
        props,
        {
            privatePreviewUrl:
                `/v1/dzcs/${route.params.documentId}/preview`,
        },
    )

/* -------------------------------------------------------------------------- */
/* State                                                                      */
/* -------------------------------------------------------------------------- */

const visible =
    ref(false)

const busy =
    ref(false)

const error =
    ref('')

const options =
    ref<any>(
        null,
    )

const endKm =
    ref<number | null>(
        null,
    )

/* -------------------------------------------------------------------------- */
/* Calculated values                                                          */
/* -------------------------------------------------------------------------- */

const startKm =
    computed(
        () => {
            if (
                endKm.value ===
                    null ||
                !options.value
            ) {
                return null
            }

            return (
                Math.round(
                    (
                        endKm.value -
                        Number(
                            options.value
                                .calculated_km,
                        )
                    ) *
                        1000,
                ) /
                1000
            )
        },
    )

const valid =
    computed(
        () =>
            endKm.value !==
                null &&
            Number.isFinite(
                endKm.value,
            ) &&
            endKm.value >=
                0 &&
            endKm.value <=
                999999999 &&
            startKm.value !==
                null &&
            startKm.value >=
                0 &&
            (
                !options.value
                    ?.previous ||
                endKm.value >=
                    Number(
                        options.value
                            .previous
                            .end_km,
                    )
            ),
    )

/* -------------------------------------------------------------------------- */
/* Document actions                                                           */
/* -------------------------------------------------------------------------- */

const actions =
    computed(
        () => [
            ...(
                !props.isPublic
                    ? [
                          {
                              id:
                                  'download-xlsx',

                              icon:
                                  'bi bi-filetype-xlsx',

                              label:
                                  'Exportovať XLSX',

                              compact:
                                  true,
                          },
                      ]
                    : []
            ),
        ],
    )

const downloadOptions =
    computed(
        () => [
            {
                label:
                    'PDF',

                fileType:
                    'PDF',

                contentType:
                    'application/pdf',

                url:
                    props.isPublic &&
                    props.signature
                        ? getPublicLink({
                              download:
                                  true,

                              format:
                                  'pdf',
                          })
                        : `/api/v1/dzcs/${documentId.value}/download`,

                filename:
                    `dzc_${documentId.value}.pdf`,
            },
        ],
    )

/* -------------------------------------------------------------------------- */
/* Helpers                                                                    */
/* -------------------------------------------------------------------------- */

function saveBlob(
    data: BlobPart,
    mimeType: string,
    filename: string,
) {
    const url =
        URL.createObjectURL(
            new Blob(
                [
                    data,
                ],
                {
                    type:
                        mimeType,
                },
            ),
        )

    const anchor =
        document.createElement(
            'a',
        )

    anchor.href =
        url

    anchor.download =
        filename

    anchor.click()

    setTimeout(
        () =>
            URL.revokeObjectURL(
                url,
            ),
        100,
    )
}

async function showError(
    exception: any,
) {
    let body =
        exception?.response
            ?.data

    if (
        body instanceof
        Blob
    ) {
        try {
            body =
                JSON.parse(
                    await body.text(),
                )
        } catch {
            body =
                null
        }
    }

    error.value =
        body?.errors
            ? Object.values(
                  body.errors,
              )
                  .flat()
                  .join(
                      '\n',
                  )
            : (
                  body?.message ??
                  'Export sa nepodaril. Skúste ho znova.'
              )
}

/* -------------------------------------------------------------------------- */
/* Actions                                                                    */
/* -------------------------------------------------------------------------- */

async function handleActionClick(
    actionId: string,
) {
    if (
        busy.value
    ) {
        return
    }

    const id =
        String(
            documentId.value,
        )

    error.value =
        ''

    busy.value =
        true

    try {
        /* XLSX */

        if (
            actionId ===
                'download-xlsx' &&
            !props.isPublic
        ) {
            /*
             * Every export asks for the odometer again,
             * even when an older reading exists.
             */
            options.value =
                null

            endKm.value =
                null

            const response =
                await api.get(
                    `/v1/dzcs/${id}/export-options`,
                )

            options.value =
                response.data
                    .data

            endKm.value =
                options.value
                    .end_km

            visible.value =
                true

            return
        }
    } catch (exception) {
        await showError(
            exception,
        )
    } finally {
        busy.value =
            false
    }
}

/* -------------------------------------------------------------------------- */
/* XLSX export                                                                */
/* -------------------------------------------------------------------------- */

async function exportXlsx() {
    if (
        busy.value ||
        !valid.value ||
        !options.value
    ) {
        return
    }

    busy.value =
        true

    error.value =
        ''

    try {
        const response =
            await api.post(
                `/v1/dzcs/${documentId.value}/xlsx`,
                {
                    end_km:
                        endKm.value,

                    route_fingerprint:
                        options.value
                            .route_fingerprint,
                },
                {
                    responseType:
                        'blob',
                },
            )

        saveBlob(
            response.data,
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            `kniha_jazd_${documentId.value}.xlsx`,
        )

        visible.value =
            false
    } catch (exception) {
        await showError(
            exception,
        )
    } finally {
        busy.value =
            false
    }
}

function closeExportDialog() {
    if (
        busy.value
    ) {
        return
    }

    visible.value =
        false
}
</script>

<template>
    <!-- ================================================================ -->
    <!-- PAGE ERROR                                                       -->
    <!-- ================================================================ -->

    <div
        v-if="
            error &&
            !visible
        "
        role="
            alert
        "
        class="
            mb-4
            rounded-md
            bg-danger/5
            p-4
        "
    >
        <p
            class="
                whitespace-pre-line
                text-normal
                text-danger
            "
        >
            {{
                error
            }}
        </p>
    </div>

    <!-- ================================================================ -->
    <!-- DOCUMENT                                                         -->
    <!-- ================================================================ -->

    <DocumentShell
        title="
            Denný záznam ciest
        "
        :previewUrl="
            previewUrl
        "
        :actions="
            actions
        "
        :downloadOptions="
            downloadOptions
        "
        :showPrintButton="
            true
        "
        @actionClick="
            handleActionClick
        "
    />

    <!-- ================================================================ -->
    <!-- XLSX EXPORT                                                      -->
    <!-- ================================================================ -->

    <Dialog
        v-model:visible="
            visible
        "
        modal
        :closable="
            !busy
        "
        :style="{
            width:
                'min(560px, 94vw)',
        }"
    >
        <template #header>
            <div
                class="
                    flex
                    flex-col
                    gap-1
                "
            >
                <p
                    class="
                        text-heading
                        text-darkgrey
                    "
                >
                    Export denného záznamu ciest
                </p>
            </div>
        </template>

        <form
            v-if="
                options
            "
            class="
                flex
                flex-col
                gap-6
            "
            @submit.prevent="
                exportXlsx
            "
        >
            <!-- ======================================================== -->
            <!-- ODOMETER                                                -->
            <!-- ======================================================== -->

            <div
                class="
                    flex
                    flex-col
                    gap-4
                "
            >
                <FormField
                    :label="`Zadajte stav tachometra po poslednej jazde dňa ${options.reading_date}`"
                    required
                    outlined
                >
                    <template
                        #default="{
                            id,
                            describedBy,
                            invalid,
                        }"
                    >
                        <InputNumber
                            :input-id="id"
                            v-model="endKm"
                            :min="0"
                            :max="999999999"
                            :max-fraction-digits="3"
                            :use-grouping="false"
                            :disabled="busy"
                            :invalid="invalid"
                            :aria-describedby="describedBy"
                            fluid
                        />
                    </template>
                </FormField>
            </div>

            <!-- ======================================================== -->
            <!-- WARNINGS                                                -->
            <!-- ======================================================== -->

            <div
                v-if="
                    options.route_changed
                "
                class="
                    flex
                    items-start
                    gap-3
                    rounded-md
                    bg-warning/10
                    p-4
                "
            >
                <i
                    class="
                        bi
                        bi-exclamation-triangle
                        mt-0.5
                        text-warning
                    "
                    aria-hidden="true"
                />

                <p
                    class="
                        text-mini
                        text-darkgrey
                    "
                >
                    Trasa sa od predchádzajúceho exportu zmenila. Overte zadaný konečný stav pre tento záznam.
                </p>
            </div>

            <!-- ======================================================== -->
            <!-- VALIDATION                                              -->
            <!-- ======================================================== -->

            <p
                v-if="
                    endKm !==
                        null &&
                    !valid
                "
                role="
                    alert
                "
                class="
                    text-mini
                    text-danger
                "
            >
                Konečný stav musí pokryť vypočítané kilometre a nesmie byť nižší než predchádzajúci zadaný stav.
            </p>

            <p
                v-if="
                    error
                "
                role="
                    alert
                "
                class="
                    whitespace-pre-line
                    text-mini
                    text-danger
                "
            >
                {{
                    error
                }}
            </p>

            <!-- ======================================================== -->
            <!-- ACTIONS                                                 -->
            <!-- ======================================================== -->

            <div
                class="
                    flex
                    items-center
                    justify-end
                    gap-2
                "
            >
                <AppButton
                    type="button"
                    variant="secondary"
                    label="
                        Zrušiť
                    "
                    :disabled="
                        busy
                    "
                    @click="
                        closeExportDialog
                    "
                />

                <AppButton
                    type="submit"
                    variant="accent"
                    label="
                        Stiahnuť XLSX
                    "
                    :loading="
                        busy
                    "
                    loading-label="
                        Exportujem
                    "
                    :disabled="
                        busy ||
                        !valid
                    "
                />
            </div>
        </form>
    </Dialog>
</template>