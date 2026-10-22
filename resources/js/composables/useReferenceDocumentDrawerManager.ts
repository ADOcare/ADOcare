import {
    computed,
    ref,
    type ComputedRef,
} from 'vue'

type DrawerCloseHandler =
    () => void

const registeredDrawerIds =
    ref<string[]>([])

const activeDrawerId =
    ref<string | null>(
        null,
    )

const initialIntroOwnerId =
    ref<string | null>(
        null,
    )

const closeHandlers =
    new Map<
        string,
        DrawerCloseHandler
    >()

let drawerIdCounter = 0

function resetSharedStateIfEmpty() {
    if (
        registeredDrawerIds.value.length
    ) {
        return
    }

    activeDrawerId.value =
        null

    initialIntroOwnerId.value =
        null
}

export function useReferenceDocumentDrawerManager() {
    function createDrawerId() {
        drawerIdCounter++

        return `reference-document-drawer-${drawerIdCounter}`
    }

    function registerDrawer(
        id: string,
        closeHandler:
            DrawerCloseHandler,
    ) {
        closeHandlers.set(
            id,
            closeHandler,
        )

        if (
            registeredDrawerIds.value.includes(
                id,
            )
        ) {
            return
        }

        registeredDrawerIds.value = [
            ...registeredDrawerIds.value,
            id,
        ]
    }

    function unregisterDrawer(
        id: string,
    ) {
        closeHandlers.delete(
            id,
        )

        registeredDrawerIds.value =
            registeredDrawerIds.value.filter(
                drawerId =>
                    drawerId !==
                    id,
            )

        if (
            activeDrawerId.value ===
            id
        ) {
            activeDrawerId.value =
                null
        }

        resetSharedStateIfEmpty()
    }

    function activateDrawer(
        id: string,
    ) {
        const previousId =
            activeDrawerId.value

        if (
            previousId &&
            previousId !== id
        ) {
            closeHandlers.get(
                previousId,
            )?.()
        }

        activeDrawerId.value =
            id
    }

    function deactivateDrawer(
        id: string,
    ) {
        if (
            activeDrawerId.value !==
            id
        ) {
            return
        }

        activeDrawerId.value =
            null
    }

    function claimInitialIntro(
        id: string,
    ) {
        if (
            initialIntroOwnerId.value ===
            null
        ) {
            initialIntroOwnerId.value =
                id

            return true
        }

        return (
            initialIntroOwnerId.value ===
            id
        )
    }

    function getDrawerIndex(
        id: string,
    ): ComputedRef<number> {
        return computed(
            () => {
                const index =
                    registeredDrawerIds.value.indexOf(
                        id,
                    )

                return Math.max(
                    index,
                    0,
                )
            },
        )
    }

    function isDrawerActive(
        id: string,
    ): ComputedRef<boolean> {
        return computed(
            () =>
                activeDrawerId.value ===
                id,
        )
    }

    return {
        activeDrawerId,
        registeredDrawerIds,

        createDrawerId,
        registerDrawer,
        unregisterDrawer,

        activateDrawer,
        deactivateDrawer,

        claimInitialIntro,
        getDrawerIndex,
        isDrawerActive,
    }
}
