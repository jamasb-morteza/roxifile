<script setup lang="ts">
import type { HTMLAttributes } from "vue"
import { PanelLeftClose, PanelLeftOpen, PanelRightClose, PanelRightOpen } from "@lucide/vue"
import { useLocalization } from "@/composables/useLocalization"
import { cn } from "@/lib/utils"
import { Button } from '@/components/ui/button'
import { useSidebar } from "./utils"

const props = defineProps<{
  class?: HTMLAttributes["class"]
}>()

const { isMobile, state, toggleSidebar } = useSidebar()
const { direction, t } = useLocalization()
</script>

<template>
  <Button
    data-sidebar="trigger"
    data-slot="sidebar-trigger"
    variant="ghost"
    size="icon"
    :class="cn('size-11 shrink-0', props.class)"
    @click="toggleSidebar"
  >
    <template v-if="direction === 'rtl'">
      <PanelRightOpen v-if="isMobile || state === 'collapsed'" />
      <PanelRightClose v-else />
    </template>
    <template v-else>
      <PanelLeftOpen v-if="isMobile || state === 'collapsed'" />
      <PanelLeftClose v-else />
    </template>
    <span class="sr-only">{{ t('Toggle sidebar') }}</span>
  </Button>
</template>
