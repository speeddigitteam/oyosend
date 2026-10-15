<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import Button from '@/Core/Components/Button.vue';
import Card from '@/Core/Components/Card.vue';
import Input from '@/Core/Components/Input.vue';
import PageHeader from '@/Core/Components/PageHeader.vue';
import Textarea from '@/Core/Components/Textarea.vue';
import PlatformLayout from '@/Core/Layouts/PlatformLayout.vue';
import { route } from '@/Core/lib/route';
import BrandImages from '../../Components/Website/BrandImages.vue';
import WebsiteNav from '../../Components/Website/WebsiteNav.vue';

defineOptions({ layout: PlatformLayout });

type Network = 'facebook' | 'linkedin' | 'youtube' | 'instagram' | 'x';

const props = defineProps<{
    company: {
        name: string | null;
        email: string | null;
        phone_1: string | null;
        phone_2: string | null;
        street: string | null;
        locality: string | null;
        city: string | null;
        postal_code: string | null;
        address_bn: string | null;
        map_url: string | null;
        contact_recipient: string | null;
        social: Record<Network, string | null>;
    };
    networks: Network[];
    /** The uploaded logo, dark-background logo and favicon, or null for the built-in ones. */
    brand: Record<'logo' | 'logo_dark' | 'favicon', string | null>;
    website: string;
}>();

const { t } = useI18n();

const form = useForm({
    name: props.company.name ?? '',
    email: props.company.email ?? '',
    phone_1: props.company.phone_1 ?? '',
    phone_2: props.company.phone_2 ?? '',
    street: props.company.street ?? '',
    locality: props.company.locality ?? '',
    city: props.company.city ?? '',
    postal_code: props.company.postal_code ?? '',
    address_bn: props.company.address_bn ?? '',
    map_url: props.company.map_url ?? '',
    contact_recipient: props.company.contact_recipient ?? '',
    social: Object.fromEntries(props.networks.map((network) => [network, props.company.social[network] ?? ''])) as Record<Network, string>,
});

function socialError(network: Network): string | undefined {
    return (form.errors as Record<string, string | undefined>)[`social.${network}`];
}

function save(): void {
    form.put(route('platform.website.company.update'), { preserveScroll: true });
}
</script>

<template>
    <PageHeader :eyebrow="t('platformWebsite.eyebrow')" :title="t('platformWebsite.company.title')" :description="t('platformWebsite.company.description')">
        <template #actions>
            <Button :href="website" native target="_blank" rel="noopener">{{ t('platformWebsite.open') }}</Button>
        </template>
    </PageHeader>

    <WebsiteNav current="company" />

    <!-- Uploaded on their own, straight away; the details below save together. -->
    <BrandImages class="mb-3" :brand="brand" />

    <!-- One box per kind of detail, side by side on wide screens; one save beneath them. -->
    <form novalidate class="grid gap-3 lg:grid-cols-2 xl:grid-cols-3" @submit.prevent="save">
        <Card :title="t('platformWebsite.company.identity.title')" :description="t('platformWebsite.company.identity.description')">
            <div class="grid gap-4">
                <Input v-model="form.name" name="name" :label="t('platformWebsite.company.name')" :error="form.errors.name" maxlength="80" required />
                <Input
                    v-model="form.email"
                    name="email"
                    type="email"
                    :label="t('platformWebsite.company.email')"
                    :hint="t('platformWebsite.company.emailHint')"
                    :error="form.errors.email"
                    maxlength="150"
                    required
                />
            </div>
        </Card>

        <Card :title="t('platformWebsite.company.phones.title')" :description="t('platformWebsite.company.phones.description')">
            <div class="grid gap-4">
                <Input
                    v-model="form.phone_1"
                    name="phone_1"
                    type="tel"
                    control-class="tabular"
                    :label="t('platformWebsite.company.phone1')"
                    :hint="t('platformWebsite.company.phoneHint')"
                    :error="form.errors.phone_1"
                    maxlength="40"
                    required
                />
                <Input
                    v-model="form.phone_2"
                    name="phone_2"
                    type="tel"
                    control-class="tabular"
                    :label="t('platformWebsite.company.phone2')"
                    :error="form.errors.phone_2"
                    maxlength="40"
                    optional
                />
            </div>
        </Card>

        <Card class="lg:col-span-2 xl:col-span-1" :title="t('platformWebsite.company.contact.title')" :description="t('platformWebsite.company.contact.description')">
            <Input
                v-model="form.contact_recipient"
                name="contact_recipient"
                type="email"
                :label="t('platformWebsite.company.recipient')"
                :hint="t('platformWebsite.company.recipientHint')"
                :error="form.errors.contact_recipient"
                maxlength="150"
                required
            />
        </Card>

        <Card class="lg:col-span-2" :title="t('platformWebsite.company.address.title')" :description="t('platformWebsite.company.address.description')">
            <div class="@container">
                <div class="grid gap-4 @2xl:grid-cols-2">
                    <div class="grid content-start gap-4 @lg:grid-cols-2">
                        <div class="@lg:col-span-2">
                            <Input v-model="form.street" name="street" :label="t('platformWebsite.company.street')" :error="form.errors.street" maxlength="160" required />
                        </div>
                        <Input v-model="form.locality" name="locality" :label="t('platformWebsite.company.locality')" :error="form.errors.locality" maxlength="80" optional />
                        <Input v-model="form.city" name="city" :label="t('platformWebsite.company.city')" :error="form.errors.city" maxlength="80" required />
                        <Input v-model="form.postal_code" name="postal_code" :label="t('platformWebsite.company.postalCode')" :error="form.errors.postal_code" maxlength="12" optional />
                    </div>
                    <div class="grid content-start gap-4">
                        <Textarea
                            v-model="form.address_bn"
                            name="address_bn"
                            lang="bn"
                            :rows="3"
                            :label="t('platformWebsite.company.addressBn')"
                            :hint="t('platformWebsite.company.addressBnHint')"
                            :error="form.errors.address_bn"
                            maxlength="400"
                            optional
                        />
                        <Input
                            v-model="form.map_url"
                            name="map_url"
                            type="url"
                            :label="t('platformWebsite.company.mapUrl')"
                            :hint="t('platformWebsite.company.mapUrlHint')"
                            :error="form.errors.map_url"
                            maxlength="500"
                            optional
                        />
                    </div>
                </div>
            </div>
        </Card>

        <Card :title="t('platformWebsite.company.social.title')" :description="t('platformWebsite.company.social.description')">
            <div class="grid gap-4">
                <Input
                    v-for="network in networks"
                    :key="network"
                    v-model="form.social[network]"
                    :name="`social.${network}`"
                    type="url"
                    :label="t(`platformWebsite.company.networks.${network}`)"
                    :placeholder="t('platformWebsite.company.socialHint')"
                    :error="socialError(network)"
                    maxlength="300"
                    optional
                />
            </div>
        </Card>

        <div class="flex justify-end lg:col-span-2 xl:col-span-3">
            <Button type="submit" variant="primary" :loading="form.processing">{{ t('platformWebsite.company.save') }}</Button>
        </div>
    </form>
</template>
