---
paths:
    - 'resources/js/pages/**'
---

# Pages

## JSON endpoints: useHttp/fetch, never the Inertia router

Domains/Files and Domains/Telegram mutation endpoints return plain JSON (no X-Inertia header). Calling them via router.post/delete or useForm().post/patch makes the Inertia client throw "plain JSON response was received". Use useHttp() from @inertiajs/vue3 (same API surface as useForm: data, errors, processing, post/patch/delete) or plain fetch with Accept: application/json. Reserve router.visit/get and useForm for endpoints that return Inertia responses (redirect/render). After a standalone mutation that changes page props, refresh with router.reload({ only: [...] }) — include every prop the UI renders from (e.g. files AND directories on files/Index.vue).
