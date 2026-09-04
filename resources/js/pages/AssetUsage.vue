<script>
import { Head } from '@statamic/cms/inertia'
import {
    Alert,
    Badge,
    Button,
    Card,
    ConfirmationModal,
    Header,
    Input,
    Pagination,
    Select,
    Text,
} from '@statamic/cms/ui'
import ListSkeleton from '../components/ListSkeleton.vue'
import RowCheckbox from '../components/RowCheckbox.vue'
import UsageList from '../components/UsageList.vue'

/**
 * Stands in for "any site" in the filter. An option can't carry an empty
 * value (the Select refuses to open when one does) and no site handle can
 * contain an asterisk, so this can never collide with a real one.
 */
const ANY_SITE = '*'

export default {
    components: {
        Alert,
        Badge,
        Button,
        Card,
        ConfirmationModal,
        Head,
        Header,
        Input,
        ListSkeleton,
        Pagination,
        RowCheckbox,
        Select,
        Text,
        UsageList,
    },

    props: {
        icon: String,
        multisite: Boolean,
        canDelete: Boolean,
        assetsUrl: String,
        rebuildUrl: String,
        statusUrl: String,
        destroyUrl: String,
        destroyUnusedUrl: String,
        containers: Array,
        sites: Array,
        minimumAgeInDays: Number,
    },

    data() {
        return {
            assets: [],
            meta: null,
            index: { exists: false, stale: true, aged: false, auto_update: true, building: false, built_at: null, items_scanned: 0 },
            filters: { usage: 'all', container: null, site: ANY_SITE, search: '', sort: 'name_asc' },
            page: 1,
            /** False until the first response lands, so nothing flashes an empty or stale state. */
            ready: false,
            loading: true,
            rebuilding: false,
            deleting: false,
            selected: [],
            expanded: [],
            confirming: null,
            confirmingAllUnused: false,
            searchTimeout: null,
            poll: null,
        }
    },

    computed: {
        usageOptions() {
            return [
                { value: 'all', label: __('asset-usage::messages.filters.all') },
                { value: 'used', label: __('asset-usage::messages.filters.used') },
                { value: 'unused', label: __('asset-usage::messages.filters.unused') },
            ]
        },

        sortOptions() {
            return [
                { value: 'name_asc', label: __('asset-usage::messages.sort.name_asc') },
                { value: 'name_desc', label: __('asset-usage::messages.sort.name_desc') },
                { value: 'used', label: __('asset-usage::messages.sort.used') },
                { value: 'unused', label: __('asset-usage::messages.sort.unused') },
            ]
        },

        containerOptions() {
            return this.containers.map(container => ({ value: container.handle, label: container.title }))
        },

        siteOptions() {
            return [
                { value: ANY_SITE, label: __('asset-usage::messages.filters.all_sites') },
                ...this.sites.map(site => ({ value: site.handle, label: site.title })),
            ]
        },

        /** The sentinel goes out as an empty value, which the server reads as "any site". */
        requestParams() {
            return {
                ...this.filters,
                site: this.filters.site === ANY_SITE ? '' : this.filters.site,
                page: this.page,
            }
        },

        siteTitles() {
            return this.sites.reduce((titles, site) => ({ ...titles, [site.handle]: site.title }), {})
        },

        /** Rows that may actually be deleted, which is what the header checkbox toggles. */
        deletableAssets() {
            return this.assets.filter(asset => !asset.blocker)
        },

        allDeletableSelected() {
            return this.deletableAssets.length > 0 && this.deletableAssets.every(asset => this.isSelected(asset.id))
        },

        someDeletableSelected() {
            return !this.allDeletableSelected && this.deletableAssets.some(asset => this.isSelected(asset.id))
        },

        /**
         * The button stays visible while nothing is selected, so it only carries
         * a count once there is one, because "(0)" reads as a broken counter.
         */
        deleteSelectedText() {
            const label = __('asset-usage::messages.delete.selected')

            return this.selected.length ? `${label} (${this.selected.length})` : label
        },

        /**
         * What an overdue rebuild is worth saying depends on whether anything
         * has been keeping the index current in the meantime.
         */
        agedInstructions() {
            const key = this.index.auto_update ? 'aged_instructions' : 'aged_instructions_manual'

            return __(`asset-usage::messages.index.${key}`, { time: this.index.built_at_relative })
        },

        busy() {
            return this.loading || this.rebuilding || this.deleting
        },

        unusedTotal() {
            return this.meta?.unused_total ?? 0
        },

        confirmationOpen() {
            return this.confirming !== null || this.confirmingAllUnused
        },

        /** One asset or many changes the wording, so both read naturally. */
        confirmationCount() {
            return this.confirmingAllUnused ? this.unusedTotal : (this.confirming?.length ?? 0)
        },

        confirmationTitle() {
            return __n('asset-usage::messages.delete.confirm_title', this.confirmationCount, {
                count: this.confirmationCount,
            })
        },

        confirmationText() {
            return __n('asset-usage::messages.delete.confirm', this.confirmationCount, {
                count: this.confirmationCount,
            })
        },

        confirmationBody() {
            if (!this.confirming) return ''

            const paths = this.confirming.map(asset => asset.path)

            return paths.length <= 5 ? paths.join('\n') : `${paths.slice(0, 5).join('\n')}\n…`
        },

        /** Nothing to list when the whole filtered set is at stake, so say what it covers. */
        confirmationScope() {
            return this.confirmingAllUnused ? __('asset-usage::messages.delete.all_unused_scope') : ''
        },
    },

    watch: {
        'filters.usage': 'reload',
        'filters.container': 'reload',
        'filters.site': 'reload',
        'filters.sort': 'reload',

        'filters.search'() {
            clearTimeout(this.searchTimeout)
            this.searchTimeout = setTimeout(() => this.reload(), 300)
        },
    },

    mounted() {
        this.load()
    },

    beforeUnmount() {
        clearTimeout(this.searchTimeout)
        this.stopPolling()
    },

    methods: {
        load() {
            this.loading = true

            this.$axios
                .get(this.assetsUrl, { params: this.requestParams })
                .then(response => {
                    this.assets = response.data.data
                    this.meta = response.data.meta
                    this.setIndex(response.data.meta.index)
                    this.selected = []
                })
                .catch(error => this.$toast.error(this.errorMessage(error)))
                .finally(() => {
                    this.loading = false
                    this.ready = true
                })
        },

        reload() {
            this.page = 1
            this.load()
        },

        goToPage(page) {
            this.page = page
            this.load()
        },

        rebuild() {
            this.rebuilding = true

            this.$axios
                .post(this.rebuildUrl)
                .then(response => {
                    this.$toast.success(response.data.message)
                    this.setIndex(response.data.index)
                    this.load()
                })
                .catch(error => this.$toast.error(this.errorMessage(error)))
                .finally(() => (this.rebuilding = false))
        },

        /**
         * A queued rebuild finishes outside this request, so keep checking until
         * the index reports itself done. Under the sync queue it is already
         * finished by the time the response lands and this never starts.
         */
        setIndex(index) {
            this.index = index

            if (index.building) {
                this.startPolling()
            } else {
                this.stopPolling()
            }
        },

        startPolling() {
            if (this.poll) return

            this.poll = setInterval(() => {
                this.$axios.get(this.statusUrl).then(response => {
                    const index = response.data.index

                    if (!index.building) {
                        this.stopPolling()
                        this.load()
                    }

                    this.index = index
                })
            }, 3000)
        },

        stopPolling() {
            if (!this.poll) return

            clearInterval(this.poll)
            this.poll = null
        },

        confirmDelete(assets) {
            if (!this.canDelete || assets.length === 0) return

            this.confirming = assets
        },

        confirmDeleteAllUnused() {
            if (!this.canDelete || !this.unusedTotal) return

            this.confirmingAllUnused = true
        },

        closeConfirmation() {
            this.confirming = null
            this.confirmingAllUnused = false
        },

        destroy() {
            if (this.confirmingAllUnused) {
                this.send(this.$axios.delete(this.destroyUnusedUrl, { params: this.requestParams }))

                return
            }

            if (!this.confirming) return

            this.send(this.$axios.delete(this.destroyUrl, { data: { ids: this.confirming.map(asset => asset.id) } }))
        },

        /** Both delete endpoints answer the same way: a count, per-asset errors and a message. */
        send(request) {
            this.deleting = true

            request
                .then(response => {
                    const errors = Object.values(response.data.errors ?? {})

                    if (response.data.deleted > 0) this.$toast.success(response.data.message)

                    errors.forEach(error => this.$toast.error(error))

                    this.load()
                })
                .catch(error => this.$toast.error(this.errorMessage(error)))
                .finally(() => {
                    this.deleting = false
                    this.closeConfirmation()
                })
        },

        toggleSelected(id) {
            this.selected = this.isSelected(id)
                ? this.selected.filter(selected => selected !== id)
                : [...this.selected, id]
        },

        toggleAll() {
            this.selected = this.allDeletableSelected ? [] : this.deletableAssets.map(asset => asset.id)
        },

        isSelected(id) {
            return this.selected.includes(id)
        },

        selectedAssets() {
            return this.assets.filter(asset => this.isSelected(asset.id))
        },

        toggleExpanded(id) {
            this.expanded = this.expanded.includes(id)
                ? this.expanded.filter(expanded => expanded !== id)
                : [...this.expanded, id]
        },

        isExpanded(id) {
            return this.expanded.includes(id)
        },

        errorMessage(error) {
            return error.response?.data?.message ?? error.message
        },
    },
}
</script>

<template>
    <div>
        <Head :title="__('asset-usage::messages.nav_title')" />

        <Header :title="__('asset-usage::messages.nav_title')" :icon="icon">
            <Text
                v-if="ready && index.built_at_formatted"
                size="sm"
                variant="subtle"
                :title="index.built_at_relative"
                :text="__('asset-usage::messages.index.updated_at', { time: index.built_at_formatted })"
            />
            <Button
                variant="primary"
                :disabled="busy"
                :text="rebuilding || index.building ? __('asset-usage::messages.index.refreshing') : __('asset-usage::messages.index.refresh')"
                @click="rebuild"
            />
        </Header>

        <ListSkeleton v-if="!ready" />

        <template v-else>
        <Alert
            v-if="!containers.length"
            class="mb-4"
            variant="warning"
            :text="__('asset-usage::messages.no_containers')"
        />

        <Alert
            v-else-if="index.building"
            class="mb-4"
            :text="__('asset-usage::messages.index.checking')"
        />

        <Alert
            v-else-if="index.stale"
            class="mb-4"
            variant="warning"
            :heading="index.exists ? __('asset-usage::messages.index.stale') : __('asset-usage::messages.index.not_ready')"
            :text="index.exists ? __('asset-usage::messages.index.stale_instructions') : __('asset-usage::messages.index.not_ready_instructions')"
        />

        <!-- Deliberately not a warning: the data is usable, a rebuild is just overdue. -->
        <Alert
            v-else-if="index.aged"
            class="mb-4"
            :heading="__('asset-usage::messages.index.aged')"
            :text="agedInstructions"
        />

        <div class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-center">
            <Input
                v-model="filters.search"
                class="w-full sm:min-w-32 sm:flex-1"
                type="search"
                :placeholder="__('asset-usage::messages.filters.search_placeholder')"
            />

            <Select
                v-model="filters.usage"
                class="w-full sm:w-auto! sm:min-w-44 sm:shrink-0"
                :options="usageOptions"
                option-label="label"
                option-value="value"
            />

            <Select
                v-if="containers.length > 1"
                v-model="filters.container"
                clearable
                class="w-full sm:w-auto! sm:min-w-56 sm:shrink-0"
                :options="containerOptions"
                option-label="label"
                option-value="value"
                :placeholder="__('asset-usage::messages.filters.container')"
            />

            <Select
                v-if="multisite"
                v-model="filters.site"
                class="w-full sm:w-auto! sm:min-w-56 sm:shrink-0"
                :options="siteOptions"
                option-label="label"
                option-value="value"
            />
        </div>

        <div class="mb-4 flex flex-col items-start gap-2 sm:flex-row sm:items-center sm:justify-between">
            <Button
                v-if="canDelete"
                variant="danger"
                :disabled="busy || !selected.length"
                :text="deleteSelectedText"
                @click="confirmDelete(selectedAssets())"
            />

            <Button
                v-if="canDelete && unusedTotal"
                :disabled="busy"
                :text="`${__('asset-usage::messages.delete.all_unused')} (${unusedTotal})`"
                @click="confirmDeleteAllUnused"
            />

            <Select
                v-model="filters.sort"
                class="w-full sm:ms-auto sm:w-auto! sm:min-w-56 sm:shrink-0"
                :options="sortOptions"
                option-label="label"
                option-value="value"
            />
        </div>

        <Text
            v-if="!assets.length"
            as="p"
            class="italic"
            size="sm"
            variant="subtle"
            :text="__('asset-usage::messages.no_results')"
        />

        <Card v-else inset :class="{ 'pointer-events-none opacity-60': busy }" :aria-disabled="busy">
            <div
                v-if="canDelete && deletableAssets.length"
                class="flex items-center gap-3 border-b border-gray-200 px-4 py-2.5 dark:border-gray-700"
            >
                <RowCheckbox
                    show-label
                    :model-value="allDeletableSelected"
                    :indeterminate="someDeletableSelected"
                    :label="__('asset-usage::messages.delete.select_all')"
                    @update:model-value="toggleAll"
                />
            </div>

            <div class="divide-y divide-gray-200 dark:divide-gray-700">
                <div v-for="asset in assets" :key="asset.id" class="px-4 py-2.5">
                    <div class="flex items-center gap-3">
                        <!-- No checkbox at all when the asset can't be deleted; a disabled one only invites clicking. -->
                        <RowCheckbox
                            v-if="canDelete && !asset.blocker"
                            :model-value="isSelected(asset.id)"
                            :label="asset.path"
                            @update:model-value="toggleSelected(asset.id)"
                        />
                        <div v-else-if="canDelete" class="size-4 shrink-0" aria-hidden="true" />

                        <img
                            v-if="asset.thumbnail"
                            class="size-10 shrink-0 rounded object-cover"
                            :src="asset.thumbnail"
                            :alt="asset.basename"
                        />
                        <div
                            v-else
                            class="flex size-10 shrink-0 items-center justify-center rounded bg-gray-100 text-[10px] font-medium text-gray-500 uppercase dark:bg-gray-800 dark:text-gray-400"
                            v-text="asset.extension"
                        />

                        <div class="flex min-w-0 flex-1 items-center gap-2">
                            <a
                                class="truncate font-medium hover:underline"
                                :href="asset.edit_url"
                                target="_blank"
                                rel="noopener noreferrer"
                                v-text="asset.path"
                            />

                            <Badge v-if="containers.length > 1" :text="asset.container_title" />

                            <span class="shrink-0 text-xs text-gray-500 dark:text-gray-400" v-text="asset.size" />
                            <span class="shrink-0 text-xs text-gray-500 dark:text-gray-400" v-text="asset.last_modified" />
                        </div>

                        <Badge
                            v-if="asset.count === 0"
                            class="shrink-0"
                            color="orange"
                            :text="__('asset-usage::messages.filters.unused')"
                        />
                        <Badge
                            v-else
                            class="shrink-0"
                            :text="__n('asset-usage::messages.used_count', asset.count, { count: asset.count })"
                        />

                        <Button
                            v-if="asset.count"
                            size="sm"
                            variant="ghost"
                            class="shrink-0"
                            :icon="isExpanded(asset.id) ? 'chevron-up' : 'chevron-down'"
                            :aria-expanded="isExpanded(asset.id)"
                            :text="__('asset-usage::messages.details')"
                            @click="toggleExpanded(asset.id)"
                        />

                        <Button
                            v-if="canDelete"
                            size="sm"
                            variant="ghost"
                            icon="trash"
                            icon-only
                            class="shrink-0"
                            :disabled="!!asset.blocker"
                            :title="asset.blocker || __('asset-usage::messages.delete.action')"
                            :aria-label="__('asset-usage::messages.delete.action')"
                            @click="confirmDelete([asset])"
                        />
                    </div>

                    <UsageList
                        v-if="isExpanded(asset.id)"
                        class="mt-2 ps-7"
                        :usages="asset.usages"
                        :site-titles="siteTitles"
                        :multisite="multisite"
                    />
                </div>
            </div>
        </Card>

        <Pagination
            v-if="meta && meta.last_page > 1"
            class="mt-4"
            :class="{ 'pointer-events-none opacity-60': busy }"
            :resource-meta="meta"
            show-totals
            show-page-links
            :show-per-page-selector="false"
            @page-selected="goToPage"
        />

        <div class="mt-6 border-t border-gray-200 pt-4 text-center dark:border-gray-700">
            <Text
                as="p"
                class="mx-auto max-w-2xl"
                size="sm"
                variant="subtle"
                :text="__('asset-usage::messages.not_scanned')"
            />

            <Text
                as="p"
                class="mx-auto mt-2 max-w-2xl"
                size="sm"
                variant="subtle"
                :text="__('asset-usage::messages.disclaimer')"
            />

            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                {{ __('asset-usage::messages.made_by') }}
                <a
                    class="text-blue-600 hover:underline dark:text-blue-400"
                    href="https://statamic.com/creators/key-agency"
                    target="_blank"
                    rel="noopener"
                >Key Agency</a>
                <span class="mx-1" aria-hidden="true">·</span>
                <a
                    class="text-blue-600 hover:underline dark:text-blue-400"
                    href="https://github.com/keyagency"
                    target="_blank"
                    rel="noopener"
                >GitHub</a>
            </p>
        </div>
        </template>

        <ConfirmationModal
            :open="confirmationOpen"
            danger
            :title="confirmationTitle"
            :button-text="__('asset-usage::messages.delete.action')"
            :busy="deleting"
            @confirm="destroy"
            @update:open="open => { if (!open) closeConfirmation() }"
        >
            <p
                class="mb-2 text-gray-700 antialiased dark:text-gray-200"
                v-text="confirmationText"
            />
            <p
                v-if="confirmationScope"
                class="mb-2 text-sm text-gray-500 dark:text-gray-400"
                v-text="confirmationScope"
            />
            <pre
                v-if="confirmationBody"
                class="max-h-40 overflow-auto rounded bg-gray-100 p-2 text-xs dark:bg-gray-800"
                v-text="confirmationBody"
            />
        </ConfirmationModal>
    </div>
</template>
