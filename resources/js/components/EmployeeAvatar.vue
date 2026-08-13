<script setup>
import { onBeforeUnmount, ref, watch } from 'vue';
import { getToken } from '../lib/api';

const props = defineProps({
    employee: { type: Object, required: true },
    size: { type: String, default: 'normal' },
});

const imageUrl = ref(null);

function revokeImage() {
    if (imageUrl.value) URL.revokeObjectURL(imageUrl.value);
    imageUrl.value = null;
}

async function loadPhoto() {
    revokeImage();
    const endpoint = props.employee.profile_photo?.download_url;
    if (!endpoint) return;

    try {
        const response = await fetch(endpoint, {
            headers: {
                Accept: 'image/*',
                Authorization: `Bearer ${getToken()}`,
                'X-Requested-With': 'XMLHttpRequest',
            },
        });
        if (!response.ok) return;
        imageUrl.value = URL.createObjectURL(await response.blob());
    } catch {
        // The initials remain available if the private image cannot be loaded.
    }
}

watch(() => props.employee.profile_photo?.download_url, loadPhoto, { immediate: true });
onBeforeUnmount(revokeImage);
</script>

<template>
    <span class="employee-avatar" :class="`employee-avatar--${size}`">
        <img v-if="imageUrl" :src="imageUrl" :alt="`Fotografía de ${employee.full_name}`">
        <span v-else>{{ employee.first_names?.slice(0, 1) }}{{ employee.last_names?.slice(0, 1) }}</span>
    </span>
</template>
