<template>
	<NcAppNavigation>
		<template #list>
			<NcAppNavigationItem
				:name="t('scholiq', 'Dashboard')"
				:to="{ name: 'Dashboard' }"
				:exact="true">
				<template #icon>
					<HomeIcon :size="20" />
				</template>
			</NcAppNavigationItem>
			<NcAppNavigationItem
				:name="t('scholiq', 'Documentation')"
				@click="openLink('https://conduction.nl', '_blank')">
				<template #icon>
					<BookOpenVariantOutline :size="20" />
				</template>
			</NcAppNavigationItem>
		</template>
		<template #footer>
			<NcAppNavigationItem
				:name="t('scholiq', 'Settings')"
				:to="{ name: 'Settings' }">
				<template #icon>
					<CogIcon :size="20" />
				</template>
			</NcAppNavigationItem>
		</template>
	</NcAppNavigation>
</template>

<script>
import { NcAppNavigation, NcAppNavigationItem } from '@nextcloud/vue'
import BookOpenVariantOutline from 'vue-material-design-icons/BookOpenVariantOutline.vue'
import CogIcon from 'vue-material-design-icons/Cog.vue'
import HomeIcon from 'vue-material-design-icons/Home.vue'

export default {
	name: 'MainMenu',
	components: {
		NcAppNavigation,
		NcAppNavigationItem,
		BookOpenVariantOutline,
		CogIcon,
		HomeIcon,
	},
	methods: {
		openLink(url, target = '_blank') {
			window.open(url, target)
		},
	},
}
</script>
