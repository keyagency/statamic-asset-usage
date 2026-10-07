/** Failed status checks in a row before polling gives up (an expired session, a server error). */
const MAX_POLL_FAILURES = 5

/**
 * Checks a status URL every few seconds, one request at a time, and gives up
 * after a few failures in a row rather than polling forever. `key` is the
 * data property that holds the interval. The component provides
 * errorMessage(), which turns a failed request into the toast's text.
 */
export default {
    methods: {
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
    },
}
