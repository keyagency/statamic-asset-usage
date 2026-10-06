<script>
import { Head } from '@statamic/cms/inertia'
import {
    Alert,
    Badge,
    Button,
    Card,
    ConfirmationModal,
    Description,
    Header,
    Heading,
    Icon,
    Input,
    Pagination,
    Panel,
    PanelFooter,
    Select,
    Text,
} from '@statamic/cms/ui'
import CompressionCard from '../components/CompressionCard.vue'
import ListSkeleton from '../components/ListSkeleton.vue'
import MarkedText from '../components/MarkedText.vue'
import PageTabs from '../components/PageTabs.vue'
import RowCheckbox from '../components/RowCheckbox.vue'
import UsageList from '../components/UsageList.vue'
import { formatBytes, formatDate, mark, parts } from '../support/formatting.js'

/**
 * Stands in for "any site" in the filter. An option can't carry an empty
 * value (the Select refuses to open when one does) and no site handle can
 * contain an asterisk, so this can never collide with a real one.
 */
const ANY_SITE = '*'

/** Failed status checks in a row before polling gives up (an expired session, a server error). */
const MAX_POLL_FAILURES = 5

export default {
    components: {
        Alert,
        Badge,
        Button,
        Card,
        CompressionCard,
        ConfirmationModal,
        Description,
        Head,
        Header,
        Heading,
        Icon,
        Input,
        ListSkeleton,
        MarkedText,
        PageTabs,
        Pagination,
        Panel,
        PanelFooter,
        RowCheckbox,
        Select,
        Text,
        UsageList,
    },

    props: {
        /** 'usage', or 'compression' for the same overview narrowed down to compressible images. */
        view: { type: String, default: 'usage' },
        usageUrl: String,
        compressionPageUrl: String,
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
        compressionEnabled: Boolean,
        canCompress: Boolean,
        analyzeUrl: String,
        compressionStatusUrl: String,
        compressUrl: String,
        logUrl: String,
    },

    data() {
        return {
            assets: [],
            meta: null,
            index: { exists: false, stale: true, aged: false, auto_update: true, building: false, built_at: null, items_scanned: 0 },
            filters: { usage: 'all', container: null, site: ANY_SITE, search: '', compression: 'all' },
            sortColumn: this.view === 'compression' ? 'savings' : 'path',
            sortDirection: this.view === 'compression' ? 'desc' : 'asc',
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
            /** Numbers each load(), so a slow earlier response can't overwrite a newer one. */
            latestLoad: 0,
            /** Where the compression analysis stands; null while compression is off. */
            compression: null,
            startingAnalysis: false,
            compressionPoll: null,
        }
    },

    computed: {
        /** Deleting belongs to the usage overview; the compression view leaves it out. */
        deletes() {
            return this.canDelete && this.view === 'usage'
        },

        compressionOptions() {
            return ['all', 'compressible', 'compressed'].map(value => ({
                value,
                label: __(`asset-usage::messages.compress.filters.${value}`),
            }))
        },

        usageOptions() {
            return [
                { value: 'all', label: __('asset-usage::messages.filters.all') },
                { value: 'used', label: __('asset-usage::messages.filters.used') },
                { value: 'unused', label: __('asset-usage::messages.filters.unused') },
            ]
        },

        /** Every column can be sorted, both ways, by clicking its heading. */
        columns() {
            return [
                { field: 'path', label: __('asset-usage::messages.columns.name') },
                { field: 'size', label: __('asset-usage::messages.columns.size') },
                { field: 'resolution', label: __('asset-usage::messages.columns.resolution') },
                { field: 'dpi', label: __('asset-usage::messages.columns.dpi') },
                ...(this.showsSavings ? [{ field: 'savings', label: __('asset-usage::messages.columns.savings') }] : []),
                { field: 'last_modified', label: __('asset-usage::messages.columns.last_modified') },
                { field: 'usage', label: __('asset-usage::messages.columns.usage') },
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
                sort: this.sortColumn,
                order: this.sortDirection,
                page: this.page,
                // Only the compression page narrows the list down by compression.
                compression: this.view === 'compression' ? this.filters.compression : undefined,
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

        /** Hidden when the image driver is missing: every cell would be empty. */
        showsSavings() {
            return this.compressionEnabled && this.compression?.available !== false
        },

        compressionAnalyzedAt() {
            if (!this.compression?.analyzed_at) return null

            return formatDate(this.compression.analyzed_at, { preset: 'datetime', month: 'long' })
        },

        /** The headline of the analysis card, with the numbers marked to stand out. */
        compressionHeadline() {
            const compression = this.compression

            if (compression.analyzing) return parts(__('asset-usage::messages.compress.analyzing'))
            if (compression.never_analyzed) return parts(__('asset-usage::messages.compress.never_analyzed_heading'))

            return parts(__n('asset-usage::messages.compress.summary', compression.compressible, {
                count: mark(compression.compressible),
                size: mark(formatBytes(compression.savable_bytes)),
                threshold: compression.threshold,
            }))
        },

        /**
         * Formatted like Statamic's own CP dates: in the browser's timezone and
         * the user's formatting locale.
         */
        builtAt() {
            return formatDate(this.index.built_at, { preset: 'datetime', month: 'long' })
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
        'filters.compression': 'reload',
        'filters.container': 'reload',
        'filters.site': 'reload',

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
        this.stopCompressionPolling()
    },

    methods: {
        load() {
            const current = ++this.latestLoad
            this.loading = true

            this.$axios
                .get(this.assetsUrl, { params: this.requestParams })
                .then(response => {
                    if (current !== this.latestLoad) return

                    this.assets = response.data.data
                    this.meta = response.data.meta
                    this.setIndex(response.data.meta.index)
                    this.setCompression(response.data.meta.compression)
                    // A reload after a rebuild or an analysis keeps what is still on the page and deletable.
                    this.selected = this.selected.filter(id => this.deletableAssets.some(asset => asset.id === id))
                })
                .catch(error => {
                    if (current === this.latestLoad) this.$toast.error(this.errorMessage(error))
                })
                .finally(() => {
                    if (current !== this.latestLoad) return

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
            this.startPoll('poll', this.statusUrl, data => {
                if (!data.index.building) {
                    this.stopPolling()
                    this.load()
                }

                this.index = data.index
            })
        },

        stopPolling() {
            this.stopPoll('poll')
        },

        /**
         * Checks a status URL every few seconds, one request at a time, and
         * gives up after a few failures in a row rather than polling forever.
         * `key` is the data property that holds the interval.
         */
        startPoll(key, url, onStatus) {
            if (this[key]) return

            let inFlight = false
            let failures = 0

            this[key] = setInterval(() => {
                if (inFlight) return

                inFlight = true

                this.$axios
                    .get(url)
                    .then(response => {
                        failures = 0
                        onStatus(response.data)
                    })
                    .catch(error => {
                        if (++failures < MAX_POLL_FAILURES) return

                        this.stopPoll(key)
                        this.$toast.error(this.errorMessage(error))
                    })
                    .finally(() => (inFlight = false))
            }, 3000)
        },

        stopPoll(key) {
            if (!this[key]) return

            clearInterval(this[key])
            this[key] = null
        },

        analyze() {
            this.startingAnalysis = true

            this.$axios
                .post(this.analyzeUrl)
                .then(response => {
                    this.$toast.success(__('asset-usage::messages.compress.analyze_started'))
                    this.setCompression(response.data.compression)
                    this.load()
                })
                .catch(error => this.$toast.error(this.errorMessage(error)))
                .finally(() => (this.startingAnalysis = false))
        },

        /** Same idea as setIndex: a queued analysis finishes outside this request. */
        setCompression(compression) {
            this.compression = compression

            if (compression?.analyzing) {
                this.startCompressionPolling()
            } else {
                this.stopCompressionPolling()
            }
        },

        startCompressionPolling() {
            this.startPoll('compressionPoll', this.compressionStatusUrl, data => {
                if (!data.compression.analyzing) {
                    this.stopCompressionPolling()
                    this.load()
                }

                this.compression = data.compression
            })
        },

        stopCompressionPolling() {
            this.stopPoll('compressionPoll')
        },

        formatBytes(bytes) {
            return formatBytes(bytes)
        },

        savingsLabel(savings) {
            return `−${Math.round(savings)}%`
        },

        compressLink(asset) {
            return `${this.compressUrl}?asset=${encodeURIComponent(asset.id)}`
        },

        confirmDelete(assets) {
            if (!this.deletes || assets.length === 0) return

            this.confirming = assets
        },

        confirmDeleteAllUnused() {
            if (!this.deletes || !this.unusedTotal) return

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

        /**
         * Same behaviour as Statamic's own listings: a second click flips the
         * direction, and a new column starts ascending, dates descending.
         */
        sortBy(field) {
            if (this.sortColumn === field) {
                this.sortDirection = this.sortDirection === 'asc' ? 'desc' : 'asc'
            } else {
                this.sortColumn = field
                this.sortDirection = field === 'last_modified' ? 'desc' : 'asc'
            }

            this.reload()
        },

        sortIcon(field) {
            if (this.sortColumn !== field) return null

            return this.sortDirection === 'asc' ? 'sort-asc' : 'sort-desc'
        },

        ariaSort(field) {
            if (this.sortColumn !== field) return 'none'

            return this.sortDirection === 'asc' ? 'ascending' : 'descending'
        },

        errorMessage(error) {
            return error.response?.data?.message ?? error.message
        },
    },
}
</script>

<template>
    <div>
        <Head :title="view === 'compression' ? __('asset-usage::messages.nav.compression') : __('asset-usage::messages.nav_title')" />

        <Header :title="__('asset-usage::messages.nav_title')" :icon="icon">
            <template v-if="view === 'usage'">
                <Text
                    v-if="ready && index.built_at"
                    size="sm"
                    variant="subtle"
                    :title="index.built_at_relative"
                    :text="__('asset-usage::messages.index.updated_at', { time: builtAt })"
                />
                <Button
                    variant="primary"
                    :disabled="busy"
                    :text="rebuilding || index.building ? __('asset-usage::messages.index.refreshing') : __('asset-usage::messages.index.refresh')"
                    @click="rebuild"
                />
            </template>

            <!-- The same place as on the overview: when it last ran, and the button that runs it. -->
            <template v-else-if="compression?.available">
                <Text
                    v-if="compressionAnalyzedAt"
                    size="sm"
                    variant="subtle"
                    :text="__('asset-usage::messages.compress.last_analyzed', { time: compressionAnalyzedAt })"
                />
                <Button
                    v-if="canCompress"
                    variant="primary"
                    :disabled="busy || startingAnalysis || compression.analyzing"
                    :text="compression.analyzing ? __('asset-usage::messages.compress.analyzing') : __('asset-usage::messages.compress.analyze')"
                    @click="analyze"
                />
            </template>
        </Header>

        <PageTabs
            :current="view"
            :usage-url="usageUrl"
            :compression-url="compressionEnabled ? compressionPageUrl : null"
            :log-url="logUrl"
        />

        <ListSkeleton v-if="!ready" />

        <template v-else>
            <Alert
                v-if="!containers.length"
                class="mb-4"
                variant="warning"
                :text="__('asset-usage::messages.no_containers')"
            />

            <Alert
                v-else-if="view === 'usage' && index.building"
                class="mb-4"
                :text="__('asset-usage::messages.index.checking')"
            />

            <Alert
                v-else-if="view === 'usage' && index.stale"
                class="mb-4"
                variant="warning"
                :heading="index.exists ? __('asset-usage::messages.index.stale') : __('asset-usage::messages.index.not_ready')"
                :text="index.exists ? __('asset-usage::messages.index.stale_instructions') : __('asset-usage::messages.index.not_ready_instructions')"
            />

            <!-- Deliberately not a warning: the data is usable, a rebuild is just overdue. -->
            <Alert
                v-else-if="view === 'usage' && index.aged"
                class="mb-4"
                :heading="__('asset-usage::messages.index.aged')"
                :text="agedInstructions"
            />

            <!--
                Everything about compression lives on its own page. The overview only keeps the
                Saving column and points there when there is something to gain. Centred on wider
                screens, where the button makes the row taller than the icon.
            -->
            <Alert
                v-if="view === 'usage' && compression?.available && compression.compressible && !compression.analyzing"
                class="mb-4 sm:items-center!"
            >
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <MarkedText :parts="compressionHeadline" marked-class="font-semibold text-green-700 dark:text-green-400" />
                    <Button
                        class="shrink-0"
                        size="sm"
                        :href="compressionPageUrl"
                        :text="__('asset-usage::messages.compress.open_page')"
                    />
                </div>
            </Alert>

            <Alert v-if="view === 'compression' && compression && !compression.available" class="mb-4" variant="warning">
                <Heading :text="__('asset-usage::messages.compress.unavailable_heading')" />
                <Description :text="__('asset-usage::messages.compress.unavailable', { driver: compression.requirements.driver })" />
                <a
                    v-if="compression.requirements.driver_install_url"
                    class="mt-1 inline-block text-sm font-medium underline hover:no-underline"
                    :href="compression.requirements.driver_install_url"
                    target="_blank"
                    rel="noopener noreferrer"
                    v-text="__('asset-usage::messages.compress.driver_link', { driver: compression.requirements.driver })"
                />
            </Alert>

            <template v-else-if="view === 'compression' && compression">
                <Alert
                    v-if="compression.settings_changed && !compression.analyzing"
                    class="mb-4"
                    variant="warning"
                    :heading="__('asset-usage::messages.compress.settings_changed_heading')"
                    :text="__('asset-usage::messages.compress.settings_changed')"
                />

                <CompressionCard class="mb-4" :compression="compression" :headline="compressionHeadline" :log-url="logUrl" />
            </template>

            <div class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-center">
                <Input
                    v-model="filters.search"
                    class="w-full sm:min-w-32 sm:flex-1"
                    type="search"
                    :placeholder="__('asset-usage::messages.filters.search_placeholder')"
                />

                <Select
                    v-if="view === 'compression'"
                    v-model="filters.compression"
                    class="w-full sm:w-auto! sm:min-w-44 sm:shrink-0"
                    :options="compressionOptions"
                    option-label="label"
                    option-value="value"
                />

                <Select
                    v-if="view === 'usage'"
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
                    v-if="multisite && view === 'usage'"
                    v-model="filters.site"
                    class="w-full sm:w-auto! sm:min-w-56 sm:shrink-0"
                    :options="siteOptions"
                    option-label="label"
                    option-value="value"
                />
            </div>

            <!-- What was deleted so far, with the way to the log that lists it. -->
            <p
                v-if="view === 'usage' && meta?.deletions?.count"
                class="mb-3 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-gray-600 dark:text-gray-400"
            >
                <Icon name="trash" class="size-4 shrink-0" />
                <span class="tabular-nums">
                    {{ __n('asset-usage::messages.log.deleted_count', meta.deletions.count, { count: meta.deletions.count }) }}
                    <span aria-hidden="true"> · </span>
                    <strong class="font-semibold" v-text="__('asset-usage::messages.log.freed', { size: formatBytes(meta.deletions.bytes) })" />
                </span>
                <Button size="sm" :href="logUrl" :text="__('asset-usage::messages.log.view')" />
            </p>

            <div v-if="deletes" class="mb-4 flex flex-col items-start gap-2 sm:flex-row sm:items-center sm:justify-between">
                <Button
                    variant="danger"
                    :disabled="busy || !selected.length"
                    :text="deleteSelectedText"
                    @click="confirmDelete(selectedAssets())"
                />

                <Button
                    v-if="unusedTotal"
                    :disabled="busy"
                    :text="`${__('asset-usage::messages.delete.all_unused')} (${unusedTotal})`"
                    @click="confirmDeleteAllUnused"
                />
            </div>

            <Text
                v-if="!assets.length"
                as="p"
                class="italic"
                size="sm"
                variant="subtle"
                :text="view !== 'compression'
                    ? __('asset-usage::messages.no_results')
                    : __(filters.compression === 'compressed' ? 'asset-usage::messages.compress.none_compressed' : 'asset-usage::messages.compress.none_compressible')"
            />

            <Panel v-else>
                <!-- Relative too, so absolutely positioned content such as the sr-only heading is clipped here as well. -->
                <Card
                    inset
                    class="relative overflow-x-auto overscroll-x-contain"
                    :class="{ 'pointer-events-none opacity-60': busy }"
                    :aria-disabled="busy"
                >
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 text-start dark:border-gray-700">
                                <th v-if="deletes" scope="col" class="w-px py-2.5 ps-4">
                                    <!-- A block-level flex box, because an inline checkbox sits on the text baseline instead of the middle. -->
                                    <div class="flex items-center">
                                        <RowCheckbox
                                            v-if="deletableAssets.length"
                                            :model-value="allDeletableSelected"
                                            :indeterminate="someDeletableSelected"
                                            :label="__('asset-usage::messages.delete.select_all')"
                                            @update:model-value="toggleAll"
                                        />
                                    </div>
                                </th>
                                <th
                                    v-for="column in columns"
                                    :key="column.field"
                                    scope="col"
                                    class="px-4 py-2.5 text-start whitespace-nowrap"
                                    :aria-sort="ariaSort(column.field)"
                                >
                                    <Button
                                        :text="column.label"
                                        :icon-append="sortIcon(column.field)"
                                        size="sm"
                                        variant="ghost"
                                        class="-my-1 -ms-3 text-sm! font-medium! text-gray-900! dark:text-gray-400!"
                                        @click="sortBy(column.field)"
                                    />
                                </th>
                                <th v-if="deletes" scope="col" class="px-4 py-2.5">
                                    <span class="sr-only" v-text="__('asset-usage::messages.delete.action')" />
                                </th>
                            </tr>
                        </thead>

                        <tbody>
                            <template v-for="asset in assets" :key="asset.id">
                                <!-- An expanded row hands its bottom border to the details row, so the two read as one. -->
                                <tr
                                    class="border-gray-200 last:border-b-0 dark:border-gray-700"
                                    :class="{ 'border-b': !isExpanded(asset.id) }"
                                >
                                    <td v-if="deletes" class="py-2.5 ps-4">
                                        <!-- No checkbox at all when the asset can't be deleted; a disabled one only invites clicking. -->
                                        <div class="flex items-center">
                                            <RowCheckbox
                                                v-if="!asset.blocker"
                                                :model-value="isSelected(asset.id)"
                                                :label="asset.path"
                                                @update:model-value="toggleSelected(asset.id)"
                                            />
                                        </div>
                                    </td>

                                    <td class="w-full px-4 py-2.5">
                                        <div class="flex min-w-56 items-center gap-3">
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

                                            <a
                                                class="font-medium break-all hover:underline"
                                                :href="asset.edit_url"
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                v-text="asset.path"
                                            />

                                            <Badge v-if="containers.length > 1" class="shrink-0" :text="asset.container_title" />
                                        </div>
                                    </td>

                                    <td class="px-4 py-2.5 whitespace-nowrap text-gray-600 dark:text-gray-400" v-text="asset.size" />
                                    <td class="px-4 py-2.5 whitespace-nowrap text-gray-600 dark:text-gray-400" v-text="asset.dimensions" />
                                    <td class="px-4 py-2.5 whitespace-nowrap text-gray-600 dark:text-gray-400" v-text="asset.dpi" />
                                    <td v-if="showsSavings" class="px-4 py-2.5 whitespace-nowrap">
                                        <template v-if="asset.compression">
                                            <Button
                                                v-if="asset.compression.compressible && canCompress"
                                                size="sm"
                                                :href="compressLink(asset)"
                                                :text="__('asset-usage::messages.compress.button', { percent: Math.round(asset.compression.savings) })"
                                            />
                                            <Badge
                                                v-else-if="asset.compression.compressible"
                                                color="green"
                                                :text="savingsLabel(asset.compression.savings)"
                                            />
                                            <!-- Links to the before and after page, which is where the original can be put back. -->
                                            <Badge
                                                v-else-if="asset.compression.status === 'compressed'"
                                                color="green"
                                                :href="canCompress ? compressLink(asset) : null"
                                                :title="canCompress ? __('asset-usage::messages.compress.view') : null"
                                                :text="asset.compression.savings
                                                    ? `${__('asset-usage::messages.compress.compressed_badge')} (${savingsLabel(asset.compression.savings)})`
                                                    : __('asset-usage::messages.compress.compressed_badge')"
                                            />
                                            <!-- Below the threshold, but still a saving. -->
                                            <span
                                                v-else-if="asset.compression.status === 'ok' && Math.round(asset.compression.savings) >= 1"
                                                class="text-gray-500 dark:text-gray-400"
                                                :title="__('asset-usage::messages.compress.below_threshold_tooltip', { threshold: compression?.threshold })"
                                                v-text="savingsLabel(asset.compression.savings)"
                                            />
                                            <span
                                                v-else-if="asset.compression.status === 'ok'"
                                                class="text-gray-500 dark:text-gray-400"
                                                :title="__('asset-usage::messages.compress.no_saving_tooltip')"
                                                v-text="__('asset-usage::messages.compress.no_saving')"
                                            />
                                            <Badge
                                                v-else-if="asset.compression.status === 'too_large'"
                                                color="orange"
                                                :text="__('asset-usage::messages.compress.too_large')"
                                                :title="__('asset-usage::messages.compress.too_large_tooltip', { width: asset.compression.width, height: asset.compression.height })"
                                            />
                                            <Badge
                                                v-else-if="asset.compression.status === 'unsupported'"
                                                :text="__('asset-usage::messages.compress.unsupported')"
                                                :title="asset.compression.reason"
                                            />
                                            <Badge
                                                v-else-if="asset.compression.status === 'error'"
                                                color="red"
                                                :text="__('asset-usage::messages.compress.failed')"
                                                :title="asset.compression.reason"
                                            />
                                            <span
                                                v-else
                                                class="text-gray-400 dark:text-gray-500"
                                                :title="__('asset-usage::messages.compress.not_analyzed_tooltip')"
                                                v-text="__('asset-usage::messages.compress.not_analyzed')"
                                            />
                                        </template>
                                    </td>
                                    <td class="px-4 py-2.5 whitespace-nowrap text-gray-600 dark:text-gray-400" v-text="asset.last_modified" />

                                    <td class="px-4 py-2.5 whitespace-nowrap">
                                        <!-- Without a current index a count of 0 means "not checked", not "unused". -->
                                        <Badge v-if="index.stale" :text="__('asset-usage::messages.index.not_ready')" />
                                        <Badge
                                            v-else-if="asset.count === 0"
                                            color="orange"
                                            :text="__('asset-usage::messages.filters.unused')"
                                        />
                                        <Button
                                            v-else
                                            size="sm"
                                            variant="ghost"
                                            class="-ms-3"
                                            :icon-append="isExpanded(asset.id) ? 'chevron-up' : 'chevron-down'"
                                            :aria-expanded="isExpanded(asset.id)"
                                            :text="__n('asset-usage::messages.used_count', asset.count, { count: asset.count })"
                                            @click="toggleExpanded(asset.id)"
                                        />
                                    </td>

                                    <td v-if="deletes" class="px-4 py-2.5 text-end">
                                        <Button
                                            v-if="!asset.blocker"
                                            size="sm"
                                            variant="ghost"
                                            icon="trash"
                                            icon-only
                                            :title="__('asset-usage::messages.delete.action')"
                                            :aria-label="__('asset-usage::messages.delete.action')"
                                            @click="confirmDelete([asset])"
                                        />
                                    </td>
                                </tr>

                                <tr v-if="isExpanded(asset.id)" class="border-b border-gray-200 last:border-b-0 dark:border-gray-700">
                                    <td v-if="deletes" />
                                    <!-- Indented past the thumbnail, so the list lines up with the file name. -->
                                    <td :colspan="columns.length + (deletes ? 1 : 0)" class="pe-4 pb-3 ps-17">
                                        <UsageList :usages="asset.usages" :site-titles="siteTitles" :multisite="multisite" />
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </Card>

                <PanelFooter v-if="meta && meta.last_page > 1">
                    <Pagination
                        :class="{ 'pointer-events-none opacity-60': busy }"
                        :resource-meta="meta"
                        show-totals
                        show-page-links
                        :show-per-page-selector="false"
                        @page-selected="goToPage"
                    />
                </PanelFooter>
            </Panel>

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
                    <span class="mx-1" aria-hidden="true">·</span>
                    <a
                        class="text-blue-600 hover:underline dark:text-blue-400"
                        href="https://statamic.com/addons/key-agency/asset-usage"
                        target="_blank"
                        rel="noopener"
                    >Statamic Marketplace</a>
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
