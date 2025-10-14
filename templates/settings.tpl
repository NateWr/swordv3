<tab id="swordv3" label="{translate key="plugins.generic.swordv3.name"}">
  <tabs :is-side-tabs="true" :track-history="true">
    <tab id="swordv3/swordv3-deposits" label="Deposit">
      <div class="flex flex-col gap-4">
        <div>Some text here</div>
        <a href="{url page="swordv3" op="deposit"}">
          Deposit All
        </a>
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