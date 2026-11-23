import { createApp, h } from 'vue';
import '../css/app.css';
import BrowserDownloads from './components/BrowserDownloads.vue';
import OfflineMediaLibrary from './components/OfflineMediaLibrary.vue';
import { browserAccount } from './lib/browserDownloads';
const account = browserAccount();
if (account)
    createApp({
        render: () =>
            h('div', { class: 'mx-auto max-w-7xl space-y-8 p-4 sm:p-6' }, [
                h(OfflineMediaLibrary, { account }),
                h(BrowserDownloads, { account, offline: true }),
            ]),
    }).mount('#browser-downloads');
else
    document.getElementById('browser-downloads')!.textContent =
        'No browser downloads are available for the active account. Sign in online to save files.';
