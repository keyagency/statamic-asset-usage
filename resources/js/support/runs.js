import { escapeHtml } from './formatting.js'

/**
 * Starting, stopping and following the two long runs on the page: the usage
 * check ('index') and the compression analysis ('compression'). The page
 * follows a run by asking for its status, never by waiting for the request
 * that started it: without PHP-FPM that request only returns once the run is
 * done. Uses the polling mixin, and the page's load() and errorMessage().
 */
export default {
    data() {
        return {
            /** The run whose start request is on its way, 'index' or 'compression'. */
            starting: null,
            /** The run whose stop request is on its way. */
            stopping: null,
            poll: null,
            compressionPoll: null,
        }
    },

    beforeUnmount() {
        this.stopPoll('poll')
        this.stopPoll('compressionPoll')
    },

    methods: {
        /** Where each run's status comes from, and how to tell it is running. */
        runOf(kind) {
            return kind === 'index'
                ? { url: this.statusUrl, poll: 'poll', running: state => !!state?.building }
                : { url: this.compressionStatusUrl, poll: 'compressionPoll', running: state => !!state?.analyzing }
        },

        startRun(kind, url) {
            this.starting = kind
            this.followRun(kind)

            this.$axios
                .post(url)
                // Asked again rather than read from the answer, which can date from before the run.
                .then(() => this.checkRun(kind))
                .catch(error => {
                    // A proxy can give up on the request while the run goes on.
                    if (!this.runOf(kind).running(this[kind])) this.$toast.error(this.errorMessage(error))
                })
                .finally(() => (this.starting = null))
        },

        /** A busy run saves what it has before it ends, so the page goes on following it until it has. */
        stopRun(kind, url) {
            this.stopping = kind

            this.$axios
                .post(url)
                .then(response => {
                    this.$toast.success(escapeHtml(response.data.message))
                    this.updateRun(kind, response.data[kind])
                })
                .catch(error => this.$toast.error(this.errorMessage(error)))
                .finally(() => (this.stopping = null))
        },

        /** The status that came with the list: follow the run while it goes. */
        setRun(kind, state) {
            this[kind] = state

            if (this.runOf(kind).running(state)) {
                this.followRun(kind)
            } else {
                this.stopPoll(this.runOf(kind).poll)
            }
        },

        followRun(kind) {
            const { url, poll } = this.runOf(kind)

            this.startPoll(poll, url, data => this.updateRun(kind, data[kind]))
        },

        checkRun(kind) {
            this.$axios
                .get(this.runOf(kind).url)
                .then(response => this.updateRun(kind, response.data[kind]))
                .catch(() => {})
        },

        /** Once the run has ended, the list is loaded again to show what it changed. */
        updateRun(kind, state) {
            if (!this.runOf(kind).running(state)) {
                this.stopPoll(this.runOf(kind).poll)
                this.load()
            }

            this[kind] = state
        },
    },
}
