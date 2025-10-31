<tab id="swordv3" label="{translate key="plugins.generic.swordv3.name"}">
  <tabs :is-side-tabs="true" :track-history="true">
    <tab id="swordv3/swordv3-overview" label="{translate key="plugins.generic.swordv3.action.deposit"}">
      <template v-if="swordv3.enabled">
        <sword-deposits-overview
          v-if="swordv3.enabled"
          :export-csv-url="swordv3.exportCsvUrl"
          :items-per-page="swordv3.itemsPerPage"
          :service-name="swordv3.serviceName"
        />
      </template>
      <div v-else>
        {translate key="plugins.generic.swordv3.serviceRequired"}
      </div>
    </tab>
    <tab id="swordv3/swordv3-settings" label="{translate key="navigation.setup"}">
			<pkp-form
				v-bind="components.swordv3service"
				@set="set"
			/>
    </tab>
  </tabs>
</tab>