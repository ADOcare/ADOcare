import Material from '@primeuix/themes/material';

import type { PrimeVueConfiguration } from 'primevue/config';
import { definePreset } from '@primeuix/themes';

const ADOPreset = definePreset(Material, {
    semantic: {
        colorScheme: {
            light: {
                formFieldPadding: {
                    y: '0.2rem',
                    x: '0.5rem',
                },
                listOptionPadding: "0.2rem 0.5rem",
                autocomplete: {
                    border: 0,
                },
            }
        }
    }
});

const baseField =
    'rounded-md! h-7! ' +
    'border-darkgrey! ' +
    'text-normal! text-darkgrey! ' +
    'outline-none! ring-0! shadow-none! ' +
    'placeholder:text-lightgrey! ' +
    'focus:outline-none! focus:ring-0! focus:shadow-none!';

const baseNoOutline =
    'outline-none! ring-0! shadow-none! ' +
    'focus:outline-none! focus:ring-0! focus:shadow-none!';

type CheckboxPtOptions = {
    context?: {
        disabled?: boolean;
        checked?: boolean;
    };
};

export default {
    license: import.meta.env.VITE_PRIMEVUE_LICENSE_KEY,
    theme: {
        preset: ADOPreset,
        options: {
            prefix: 'p',
            darkModeSelector: '.dark-theme',
            cssLayer: false
        },
    },

    pt: {
        datatable: {
            root: { class: 'rounded-md overflow-hidden' },
            table: { class: 'w-full border-collapse border-0!' },
            bodyrow: { class: '[&.p-row-odd]:bg-almostwhite! [&.p-row-odd:hover]:bg-lightgrey! hover:bg-lightgrey!' },
            tbody: { class: 'overflow-auto' }
        },

        column: {
            headercell: { class: 'bg-darkgrey! text-white! text-heading! px-4! py-2! border-r! border-r-white! last:border-r-0!' },
            sortIcon: { class: 'text-white! flex! items-center! justify-center!' },
            columnTitle: { class: 'text-white! text-heading!' },

            pcheadercheckbox: {
                root: {
                    class: 'shadow-none! hover:shadow-none! data-[p-disabled=true]:opacity-100! [&.p-disabled]:opacity-100!',
                },
                box: (options?: CheckboxPtOptions) => {
                    const context = options?.context;

                    return {
                        class: [
                            'rounded-sm! border! shadow-none!',
                            context?.disabled
                                ? 'bg-tag1! border-white!'
                                : 'bg-darkgrey! border-white!',
                        ].join(' '),
                    };
                },
                icon: (options?: CheckboxPtOptions) => ({
                    class: options?.context?.disabled
                        ? 'text-darkgrey!'
                        : 'text-white!',
                }),
            },

            pcrowcheckbox: {
                root: {
                    class: 'shadow-none! hover:shadow-none! data-[p-disabled=true]:opacity-100! [&.p-disabled]:opacity-100!',
                },
                box: (options?: CheckboxPtOptions) => {
                    const context = options?.context;

                    return {
                        class: [
                            'rounded-sm! border! shadow-none!',
                            context?.disabled
                                ? 'bg-tag1! border-darkgrey!'
                                : context?.checked
                                    ? 'bg-darkgrey! border-darkgrey!'
                                    : 'bg-white! border-darkgrey!',
                        ].join(' '),
                    };
                },
                icon: (options?: CheckboxPtOptions) => ({
                    class: options?.context?.disabled
                        ? 'text-darkgrey!'
                        : 'text-white!',
                }),
            },

            bodycell: {
                class: 'text-normal/5! text-darkgrey! px-4! py-1! border-0! border-r! border-r-white! last:border-r-0!',
            },
        },

        /* ---------- INPUTS / TEXTAREA ---------- */

        inputtext: {
            root: {
                class: `${baseField} bg-white! rounded-md! h-7! border-darkgrey! text-normal! text-darkgrey! [&.p-disabled]:!opacity-50 [&.p-disabled]:!cursor-not-allowed [&.p-disabled]:!bg-lightgrey!`
            },
        },

        incrementButton: {
            class: 'bg-transparent! border-0! text-darkgrey! hover:bg-transparent! hover:text-accent! focus:bg-transparent! rounded-md! bg-transparent!',
        },
        decrementButton: {
            class: 'bg-transparent! border-0! text-darkgrey! hover:bg-transparent! hover:text-accent! focus:bg-transparent! rounded-md! bg-transparent!',
        },

        textarea: {
            root: { class: 'bg-white! rounded-md! border-darkgrey! text-normal! text-darkgrey! outline-none! ring-0! shadow-none! focus:outline-none! focus:ring-0! focus:shadow-none!' },
        },

        button: {
            root: {
                class: `p-xs! rounded-md! ${baseNoOutline} disabled:bg-accent/50! disabled:border-accent/50! disabled:text-white! disabled:opacity-100! disabled:cursor-not-allowed!`,
            },
        },

        menubar: {
            root: { class: 'border-0! px-0! rounded-none! content-center!' },
        },

        menu: {
            root: { class: 'border-0! rounded-none!' },
            item: { class: 'hover:bg-transparent! focus:bg-transparent! rounded-md! bg-transparent!' },
            itemContent: { class: 'hover:bg-transparent! focus:bg-transparent! rounded-md! bg-transparent!' },
        },

        dialog: {
            header: { class: 'text-darkgrey! text-heading-accent!' },
            root: { class: 'rounded-md!' },
        },

        toast: {
            root: { class: 'rounded-md!' },

            message: ({ props }: any) => ({
                class: [
                    'rounded-md!',
                    'border-l-4',
                    props?.message?.severity === 'success' && 'bg-success!',
                    props?.message?.severity === 'error' && 'bg-danger!',
                    props?.message?.severity === 'warn' && 'bg-danger!',
                    props?.message?.severity === 'info' && 'bg-darkgrey!',
                ],
            }),

            messageContent: { class: 'text-white! rounded-md! px-3 py-2' },
            messageIcon: { class: 'hidden!' },
            detail: { class: 'text-white!' },
        },


        /* ---------- SELECT ---------- */

        select: {
            root: ({ props }) => ({
                class: `
            ${props.multiple ? baseField.replace('h-7!', 'min-h-7! h-auto!') : baseField}
            flex! items-center! gap-1!
            ${props.disabled ? 'bg-transparent! opacity-50!' : ''}
        `
            }),
            dropdown: { class: `${baseNoOutline} border-0! bg-transparent! px-1!` },
            dropDownIcon: { class: 'text-sm! text-darkgrey! bi! bi-chevron-down!' },
            label: { class: 'text-normal! text-darkgrey! truncate!' },
            overlay: { class: 'rounded-md! border-0!' },
            header: { class: 'p-2!' },
            listContainer: { class: 'p-2! gap-2!' },
            option: { class: 'rounded-md! hover:bg-almostwhite! text-normal! p-2! bg-white!' },
            clearIcon: { class: 'text-sm! text-darkgrey!' },
        },


        autocomplete: {
            root: {
                class: 'w-full flex flex-col items-stretch p-0 border-0! bg-transparent!'
            },

            input: {
                class: [
                    'w-full h-full border-0! bg-transparent!',
                    'text-normal! text-darkgrey!',
                    'outline-none! ring-0! shadow-none!',
                    'focus:outline-none! focus:ring-0! focus:shadow-none!'
                ].join(' ')
            },

            inputMultiple: {
                class: [
                    'w-full',
                    'rounded-md! border-0! bg-white!',
                    'px-2! py-2!',
                    'flex! flex-wrap! items-start! content-center! gap-1!',
                    'min-h-[2.5rem] max-h-40 overflow-y-auto',
                    'text-normal! text-darkgrey! outline-none! ring-0! shadow-none!',
                    'focus:outline-none! focus:ring-0! focus:shadow-none!'
                ].join(' ')
            },

            overlay: {
                class: 'rounded-md! p-2! w-auto border-0!'
            },

            option: {
                class: 'rounded-md! hover:bg-almostwhite! text-normal! p-2! bg-white! h-auto! !text-normal'
            },

            dropDown: { class: 'hidden!' },

        },


        datepicker: {
            root: { class: `${baseField} flex! items-center! rounded-md!` },
            input: {
                class: 'w-full! h-full! border-0! bg-transparent! ' +
                    'text-normal! text-darkgrey! ' +
                    'outline-none! ring-0! shadow-none! ' +
                    'focus:outline-none! focus:ring-0! focus:shadow-none!',
            },
            panel: { class: 'rounded-md! text-normal! text-darkgrey! border-0!' },
            selectMonth: { class: 'bg-darkgrey! text-normal! text-white! hover:bg-accent! rounded-md!' },
            selectYear: { class: 'bg-darkgrey! text-normal! text-white! hover:bg-accent! rounded-md!' },
            day: {
                class: [
                    'text-normal! rounded-md! hover:bg-tag3!',
                    '[&.p-datepicker-day-selected]:bg-accent! [&.p-datepicker-day-selected]:text-white!',
                    '[&.p-datepicker-day-selected-range]:bg-accent! [&.p-datepicker-day-selected-range]:text-white!'
                ].join(' ')
            },
            weekDay: { class: 'text-heading! text-darkgrey!' },
            month: { class: 'text-normal! text-darkgrey! [&.p-datepicker-month-selected]:bg-accent! [&.p-datepicker-month-selected]:text-white!' },
        },

        paginator: {
            root: { class: 'border-0! rounded-none! text-mini! p-0! bg-darkgrey! h-7! align-middle!' },
            prevIcon: { class: 'bi bi-chevron!-left text-white!' },
            prev: { class: 'p-1! rounded-md! hover:bg-tag2! text-white! h-6!' },
            nextIcon: { class: 'bi bi-chevron-right! text-white!' },
            next: { class: 'p-1! rounded-md! hover:bg-tag2! text-white! h-6!' },
            firstIcon: { class: 'bi bi-chevron-bar-left! text-white!' },
            first: { class: 'p-1! rounded-md! hover:bg-tag2! text-white! h-6!' },
            lastIcon: { class: 'bi bi-chevron-bar-right! text-white!' },
            last: { class: 'p-1! rounded-md! hover:bg-tag2! text-white! h-6!' },
            page: { class: 'p-1! rounded-md! hover:bg-tag2! text-white! h-6!' },
        },

        checkbox: {
            root: {
                class: `!shadow-none hover:!shadow-none data-[p-disabled=true]:opacity-100! [&.p-disabled]:opacity-100! ${baseNoOutline}`,
            },
            
            box: (options?: CheckboxPtOptions) => {
                const context = options?.context;

                return {
                    class: [
                        'rounded-sm! border! shadow-none!',
                        context?.disabled
                            ? context?.checked
                                ? 'bg-tag1! border-tag1! cursor-not-allowed!'
                                : 'bg-white! border-tag1! cursor-not-allowed!'
                            : context?.checked
                                ? 'bg-accent! border-accent!'
                                : 'bg-white! border-darkgrey!',
                    ].join(' '),
                };
            },
            icon: {
                class: '!text-darkgrey !fill-darkgrey',
            },
            input: { class: '!bg-darkgrey' },
        },

        radiobutton: {
            root: {
                class: `!shadow-none hover:!shadow-none ${baseNoOutline}`,
                style: {
                    '--p-radiobutton-icon-checked-color': '#2c3e50',
                    '--p-radiobutton-icon-checked-hover-color': '#2c3e50'
                }
            },
            box: {
                class: `
            !border-darkgrey
            !border-2
            data-[p-checked=true]:!bg-accent
            data-[p-checked=true]:!border-accent
            !bg-transparent
            `,
            },
            icon: {
                class: '!text-darkgrey !fill-darkgrey',
            },
            input: { class: '!bg-darkgrey' },
        },

        carousel: {
            root: { class: 'rounded-md! gap-2! align-items-center!' },
            item: { class: 'bg-white! rounded-md! w-fit! flex-none! gap-2! align-items-center!' },
            viewport: {
                class: 'gap-2! align-items-center!'
            },
            content: { class: 'gap-2! align-items-center!' },
            contentContainer: { class: 'gap-2! align-items-center!' },
        },

        tooltip: {
            root: { class: '!rounded-md !bg-darkgrey !text-white !text-mini !p-1' },
            text: { class: '!text-white !text-mini' },
        },

        tabs: {
            root: {
                class: 'flex! flex-col! gap-2! bg-transparent!'
            }
        },


        tablist: {
            root: {
                class: 'relative! w-full! min-w-0! border-0! bg-transparent! pb-9!'
            },
            tabList: {
                class: 'flex! flex-row! flex-nowrap! items-center! gap-2! m-0! border-0! bg-transparent! p-0!'
            },
            activeBar: {
                class: 'hidden!'
            },
            prevButton: {
                class: [
                    'absolute! bottom-0! top-auto! left-auto! right-8!',
                    'z-10! flex! h-7! w-4! items-center! justify-center!',
                    'cursor-pointer! border-0! bg-transparent! p-0!',
                    'text-darkgrey! text-normal! shadow-none!',
                    'hover:text-accent!',
                ].join(' ')
            },
            nextButton: {
                class: [
                    'absolute! bottom-0! top-auto! left-auto! right-0!',
                    'z-10! flex! h-7! w-4! items-center! justify-center!',
                    'cursor-pointer! border-0! bg-transparent! p-0!',
                    'text-darkgrey! text-normal! shadow-none!',
                    'hover:text-accent!'
                ].join(' ')
            }
        },


        tab: {
            root: {
                class: [
                    // Match AppButton: height, radius, font and spacing.
                    'flex! h-7! min-h-7! shrink-0! items-center! justify-center! gap-2! mr-1!',
                    'm-0! cursor-pointer! select-none! whitespace-nowrap!',
                    'rounded-md! border-0! px-3! py-0! text-normal! font-normal! normal-case!',
                    'shadow-none! no-underline! transition-colors! duration-200!',
                    // Inactive = AppButton secondary.
                    'bg-accent! text-white! hover:bg-darkgrey!',
                    // Active = AppButton accent.
                    'data-[p-active=true]:bg-darkgrey! data-[p-active=true]:text-white!',
                    'data-[p-active=true]:hover:bg-darkgrey! data-[p-active=true]:hover:text-white!',
                    // Accessible focus and disabled state.
                    'focus-visible:outline-2! focus-visible:outline-offset-2! focus-visible:outline-accent!',
                    'data-[p-disabled=true]:cursor-not-allowed! data-[p-disabled=true]:opacity-50!',
                ].join(' ')
            }
        },

        tabpanels: {
            root: {
                class: 'relative mt-0! rounded-md! border-0! bg-transparent! p-0! text-darkgrey!'
            }
        },

        tabpanel: {
            root: {
                class: 'relative rounded-md! border-0! bg-transparent! p-0! text-darkgrey!'
            }
        },

        chips: {
            root: {
                class: 'flex flex-wrap items-center gap-2 w-full! bg-white!'
            },

            input: {
                class:
                    'flex-1! min-w-[8rem]! bg-transparent! ' +
                    'border-darkgrey! rounded-md! outline-none! shadow-none! ' +
                    'text-normal! text-darkgrey! ' +
                    'focus:outline-none! focus:ring-0! focus:shadow-none!'
            },
        },

        chip: {
            root: { class: 'rounded-md! bg-darkgrey! text-white! px-2! flex items-center gap-1! h-7! text-normal!' },
            removeIcon: { class: 'text-white! text-sm!' }
        },

        toggleswitch: {
            root: {
                class: `!shadow-none hover:!shadow-none ${baseNoOutline}`,
            },
            handle: {
                class: '!bg-accent',
            },
            slider: {
                class: '!bg-tag3 !rounded-md!',
            }

        },

        popover: {
            root: {
                class: 'rounded-md! bg-white! text-darkgrey! p-2!'
            },
        },


        tag: {
            root: {
                class: 'rounded-md! text-white! text-normal! px-2! py-0! flex items-center gap-1!'
            }
        },
            

    },

} as PrimeVueConfiguration;