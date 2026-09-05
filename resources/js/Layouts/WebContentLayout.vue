<script setup>
// Minimal layout shipped with kit/webcontent so the CMS pages work in any
// Inertia host without depending on the host's own AppLayout. Replace this
// file after publishing if you want the pages to use your app's chrome.
</script>

<template>
    <div class="webcontent-layout">
        <header class="uk-container uk-margin-top">
            <slot name="header" />
        </header>
        <main class="uk-container uk-margin-top">
            <slot />
        </main>
    </div>
</template>
