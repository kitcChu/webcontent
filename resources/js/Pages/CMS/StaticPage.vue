<script setup>
import { computed, onMounted, onBeforeUnmount, watch, nextTick, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import WebContentLayout from '@/Layouts/WebContentLayout.vue';

// NOTE: the original implementation also called an app-specific interaction
// tracker here. That host-side concern is intentionally left out of the
// package; hook your analytics in via onMounted if needed.

const props = defineProps({
    page_id: [Number, String],
    title: String,
    slug: String,
    content: String,
    style: String,
    script: String,
    head_meta: Object,
    csrf_token: String,
    attach_form: String,
    success: String,
    errors: Array
});

/* ---------- dynamic <style> from the page row ---------- */
const injectStyles = (styles) => {
    if (!styles) return;
    let el = document.getElementById('dynamic-page-styles');
    if (!el) {
        el = document.createElement('style');
        el.id = 'dynamic-page-styles';
        document.head.appendChild(el);
    }
    el.textContent = styles;
};
watch(() => props.style, injectStyles);

/* ---------- derived layout flags ---------- */
const renderedForm = computed(() => {
    if (!props.attach_form) return '';
    return props.attach_form.replace(/__CSRF_TOKEN__/g, props.csrf_token || '');
});

// Rate pages (*-rate): enquiry form sticks on the left, content on the right.
const isRatePage = computed(() => /-rate$/.test(props.slug || ''));

// The migrated rate-page body is wrapped in its own uk-container + grid + main;
// inside the 2-column layout that would double-pad, so render just the <article>.
// __CSRF_TOKEN__ placeholders are swapped for the real token (content can embed
// enquiry forms directly).
const renderedContent = computed(() => {
    return String(props.content || '').replace(/__CSRF_TOKEN__/g, props.csrf_token || '');
});
const rateContent = computed(() => {
    const m = renderedContent.value.match(/<article[\s\S]*?<\/article>/i);
    return m ? m[0] : renderedContent.value;
});

// A page "opts in" to a modal enquiry form by having a CTA that points at the
// form (href="#exportEnquiryForm"). Pages without such a CTA keep the form
// rendered visibly below the content (so it never becomes unreachable).
const hasFormCta = computed(() => /href="#exportEnquiryForm"/.test(props.content || ''));

/* ---------- enquiry form wiring (submit via AJAX + open/close modal) ---------- */
const rootEl = ref(null);

function wireEnquiryForm() {
    const form = document.getElementById('exportEnquiryForm');
    if (!form || form.dataset.wired) return;
    form.dataset.wired = '1';

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        const btn = form.querySelector('[type="submit"]');
        if (btn) { btn.disabled = true; btn.classList.add('uk-disabled'); }

        fetch(form.action, {
            method: 'POST',
            body: new FormData(form),
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    const m = window.UIkit && window.UIkit.modal('#enquiry-modal');
                    if (m) m.hide();
                    notify(data.message || 'Thank you! We will be in touch shortly.', 'success');
                    form.reset();
                } else {
                    notify('Please check the form for errors.', 'danger');
                }
            })
            .catch(() => notify('An error occurred. Please try again.', 'danger'))
            .finally(() => { if (btn) { btn.disabled = false; btn.classList.remove('uk-disabled'); } });
    });
}

function notify(message, status) {
    if (window.UIkit) window.UIkit.notification({ message, status, timeout: 5000 });
}

// Clicking any CTA that targets #exportEnquiryForm opens the modal instead of
// trying to scroll to the (hidden) form.
function onRootClick(e) {
    const a = e.target.closest && e.target.closest('a[href="#exportEnquiryForm"]');
    if (!a) return;
    e.preventDefault();
    nextTick(() => {
        const m = window.UIkit && window.UIkit.modal('#enquiry-modal');
        if (m) m.show();
    });
}

onMounted(() => {
    injectStyles(props.style);

    if (rootEl.value) rootEl.value.addEventListener('click', onRootClick);
    nextTick(wireEnquiryForm);

    const hash = window.location.hash;
    if (hash === '#exportEnquiryForm') {
        nextTick(() => { const m = window.UIkit && window.UIkit.modal('#enquiry-modal'); if (m) m.show(); });
    } else if (hash) {
        nextTick(() => {
            try {
                const el = document.querySelector(hash);
                if (el) el.scrollIntoView({ behavior: 'smooth' });
            } catch (err) { /* invalid selector */ }
        });
    }
});

onBeforeUnmount(() => {
    if (rootEl.value) rootEl.value.removeEventListener('click', onRootClick);
});

watch(() => props.attach_form, () => nextTick(wireEnquiryForm));

/* ---------- helper used by some pages' inline buttons ---------- */
function setPlan(plan) {
    const select = document.getElementById('servicePlanSelect');
    if (select) {
        select.value = plan;
        select.dispatchEvent(new Event('change'));
    }
}
window.setPlan = setPlan;
</script>

<template>
    <Head>
        <title>{{ props.head_meta?.title || props.title }}</title>
    </Head>
    <WebContentLayout>
        <div ref="rootEl">
            <div v-if="props.errors && props.errors.length > 0" class="uk-alert-danger" uk-alert>{{ props.errors }}</div>
            <div v-if="props.success" class="uk-alert-success" uk-alert>{{ props.success }}</div>

            <!-- Rate pages: sticky form on the left, content on the right -->
            <div v-if="isRatePage && props.attach_form" class="uk-container uk-margin-medium-top">
                <div class="uk-grid uk-grid-small" uk-grid>
                    <aside class="uk-width-1-2@m">
                        <div class="rate-form-col">
                            <div v-html="renderedForm"></div>
                        </div>
                    </aside>
                    <div class="uk-width-1-2@m">
                        <div v-html="rateContent"></div>
                    </div>
                </div>
            </div>

            <!-- Default layout: content full width -->
            <template v-else>
                <div v-html="renderedContent"></div>
                <!-- Form rendered visibly below ONLY when the page has no enquiry CTA
                     (i.e. no modal to open it). Pages with a CTA get the modal below. -->
                <div v-if="props.attach_form && !hasFormCta" v-html="renderedForm"></div>
            </template>

            <!-- Enquiry modal: shown for non-rate pages that have an attached form
                 AND a CTA targeting #exportEnquiryForm. -->
            <div
                v-if="!isRatePage && props.attach_form && hasFormCta"
                id="enquiry-modal"
                uk-modal
            >
                <div class="uk-modal-dialog uk-modal-body uk-margin-auto-vertical uk-width-1-1 uk-width-2-3@s uk-width-1-2@m">
                    <button class="uk-modal-close-default" type="button" uk-close></button>
                    <div v-html="renderedForm"></div>
                </div>
            </div>
        </div>
    </WebContentLayout>
    <component :is="'script'" v-if="props.script" type="text/javascript">{{ props.script }}</component>
</template>

<style scoped>
/* Rate page sidebar: keep the enquiry form visible while the (taller) content
     column on the right scrolls. */
.rate-form-col {
    position: sticky;
    top: 90px;
    max-height: calc(100vh - 110px);
    overflow-y: auto;
    overflow-x: hidden;
}
/* The attached form fragment carries uk-margin-large-top; neutralise it inside
     the sidebar and inside the modal so it aligns to the top. */
.rate-form-col :deep(.uk-margin-large-top),
#enquiry-modal :deep(.uk-margin-large-top) {
    margin-top: 0;
}
</style>
