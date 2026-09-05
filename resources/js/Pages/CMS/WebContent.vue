<script setup>
import { ref, watch } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import WebContentLayout from '@/Layouts/WebContentLayout.vue';

const props = defineProps({
    title: String,
    content: String,
    style: String,
    script: String,
    slug: String,
    locale: String,
    head_meta: [Object, Array, String],
    id: Number,
    csrf_token: String,
    success: String,
    errors: Array
});

const form = useForm({
    title: props.title || '',
    content: props.content || '',
    style: props.style || '',
    script: props.script || '',
    slug: props.slug || '',
    locale: props.locale || '',
    head_meta: props.head_meta ? JSON.stringify(props.head_meta, null, 2) : '',
    _method: 'PUT'
});

const previewContent = ref(props.content || '');
const previewStyle = ref(props.style || '');
const previewScript = ref(props.script || '');

// Watch for changes in form content to update preview
watch(() => form.content, (newContent) => {
    previewContent.value = newContent;
});

watch(() => form.style, (newStyle) => {
    previewStyle.value = newStyle;
});

watch(() => form.script, (newScript) => {
    previewScript.value = newScript;
});

// NOTE: plain URLs instead of Ziggy's route() so the package does not
// require the host to run laravel-lang/ziggy. The PUT is emulated via the
// `_method: 'PUT'` field, so form.post() hits the update endpoint.
const submit = () => {
    form.post(`/web-content/${props.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            if (window.UIkit) {
                window.UIkit.notification({
                    message: 'Content updated successfully',
                    status: 'success',
                    timeout: 3000
                });
            }
        },
        onError: () => {
            if (window.UIkit) {
                window.UIkit.notification({
                    message: 'Error updating content',
                    status: 'danger',
                    timeout: 3000
                });
            }
        }
    });
};
</script>

<template>
    <Head>
        <title>Edit Page - {{ form.title }}</title>
    </Head>

    <WebContentLayout>
        <template #header>
            <h2 class="uk-heading-small">Edit Page: {{ form.title }}</h2>
        </template>

        <div class="uk-section">
            <div v-if="props.errors && props.errors.length > 0" class="uk-alert-danger" uk-alert>
                <ul>
                    <li v-for="error in props.errors" :key="error">{{ error }}</li>
                </ul>
            </div>

            <div v-if="props.success" class="uk-alert-success" uk-alert>
                {{ props.success }}
            </div>

            <div class="uk-grid uk-grid-match" uk-grid>
                <!-- Editor Panel -->
                <div class="uk-width-1-2@m">
                    <div class="uk-card uk-card-default uk-card-body">
                        <h3 class="uk-card-title">Editor</h3>
                        <form @submit.prevent="submit">
                            <div class="uk-margin">
                                <label class="uk-form-label">Title</label>
                                <input
                                    v-model="form.title"
                                    class="uk-input"
                                    type="text"
                                    required
                                >
                            </div>

                            <div class="uk-margin">
                                <label class="uk-form-label">Slug</label>
                                <input
                                    v-model="form.slug"
                                    class="uk-input"
                                    type="text"
                                    required
                                >
                            </div>

                            <div class="uk-margin">
                                <label class="uk-form-label">Language (page locale)</label>
                                <select v-model="form.locale" class="uk-select">
                                    <option value="">Default</option>
                                    <option value="en">English</option>
                                </select>
                            </div>

                            <div class="uk-margin">
                                <label class="uk-form-label">Content</label>
                                <textarea
                                    v-model="form.content"
                                    class="uk-textarea"
                                    rows="10"
                                ></textarea>
                            </div>

                            <div class="uk-margin">
                                <label class="uk-form-label">Custom CSS</label>
                                <textarea
                                    v-model="form.style"
                                    class="uk-textarea"
                                    rows="5"
                                ></textarea>
                            </div>

                            <div class="uk-margin">
                                <label class="uk-form-label">Head metadata (JSON — title, description, og, twitter, canonical, hreflang, ldjson)</label>
                                <textarea
                                    v-model="form.head_meta"
                                    class="uk-textarea uk-text-small"
                                    rows="6"
                                    placeholder='{ "description": "...", "canonical": "/slug" }'
                                ></textarea>
                            </div>

                            <div class="uk-margin">
                                <label class="uk-form-label">Custom JavaScript</label>
                                <textarea
                                    v-model="form.script"
                                    class="uk-textarea"
                                    rows="5"
                                ></textarea>
                            </div>

                            <div class="uk-margin">
                                <button
                                    type="submit"
                                    class="uk-button uk-button-primary"
                                    :disabled="form.processing"
                                >
                                    <span v-if="form.processing" uk-spinner></span>
                                    Save Changes
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Preview Panel -->
                <div class="uk-width-1-2@m">
                    <div class="uk-card uk-card-default uk-card-body">
                        <h3 class="uk-card-title">Preview</h3>
                        <div class="preview-container">
                            <div v-html="previewContent"></div>
                            <div v-html="previewStyle"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </WebContentLayout>
    <component :is="'script'" v-if="props.script" type="text/javascript">{{ props.script }}</component>
</template>

<style scoped>
.preview-container {
    min-height: 500px;
    border: 1px solid #e5e7eb;
    border-radius: 0.375rem;
    padding: 1rem;
    background: white;
}

/* Add some basic styling for the preview content */
.preview-container :deep(h1) {
    font-size: 2em;
    margin-bottom: 0.5em;
}

.preview-container :deep(h2) {
    font-size: 1.5em;
    margin-bottom: 0.5em;
}

.preview-container :deep(p) {
    margin-bottom: 1em;
}

.preview-container :deep(img) {
    max-width: 100%;
    height: auto;
}
</style>
