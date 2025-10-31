# SWORDv3 plugin for OJS

> This plugin is a prototype and should not be used in production.

An OJS plugin to deposit article metadata and PDF galleys with a SWORDv3 service.

- [x] Support Basic and APIKey authentication
- [x] Deposit metadata in DC format
- [x] Deposit PDF galleys
- [x] Automatic deposit of articles as soon as they are published
- [x] Manually deposit all previously published articles
- [x] Notify admin or journal manager when deposits fail
- [x] Export all deposit status data to CSV
- [x] Re-deposit rejected articles or all articles
- [ ] Deposit to more than one service
- [x] Deposit individual articles manually
- [ ] Deposit metadata in OpenAIRE/DataCite format
- [x] UI to view each article's deposit URL, status document and error message
- [ ] Deposit ePub, Zip, and HTML galleys
- [ ] Deposit articles before publication

## Usage

This plugin requires **OJS 3.5.0-1+**. Follow these steps to install the plugin and deposit content.

1. Install this plugin by copying or cloning this repository into OJS's `plugins/generic` directory.
1. Login tp OJS as a Journal Manager or Admin.
1. Navigate to Settings > Website > Plugins > Installed Plugins and enable the SWORDv3 Deposits plugin.
1. Navigate to Settings > Distribution > SWORDv3 Deposits > Setup.
1. Configure the connection settings for the SWORDv3 service.
2. Go to Deposits to view articles Ready for Deposit.
3. Click the Deposit button to deposit any articles Ready for Deposit.

## Testing

This plugin was built to work with this [example SWORDv3 server](https://github.com/NateWr/swordv3-example-serverl). Follow these instructions to run the example deposit server and test depositing to it.

1. Follow the [instructions](https://github.com/NateWr/swordv3-example-serverl) to run a sample server locally.
2. In OJS, navigate to Settings > Distribution > SWORDv3 Deposits > Setup.
3. Enter the Service URL, which is `http://localhost:3000/service-url` by default. (See the note on [using Docker](#docker)).
4. Choose Basic Authentication with the username `swordv3` and password `swordv3`. (If you configured an API key, you can use that instead.)
5. Save the service setup form to test the connection and authentication settings.

If the server saves successfully, you should be able to deposit to it by going to Settings > Distribution > SWORDv3 Deposits > Deposits.

### Docker

If you are running OJS from within a Docker container, it may not have access to the test server at `http://localhost`. You can get around this by using Docker's [host.docker.internal](https://www.reddit.com/r/docker/comments/ztdlo1/how_to_set_hostdockerinternal/). In my compose file, I add the following to my [OJS container](https://github.com/NateWr/pkp-docker/blob/531b2fd98021ec5da070a74ba7de2795bac4073a/compose.example.ojs-350.yaml#L19-L20):


```yaml
extra_hosts:
  - "host.docker.internal:host-gateway"
```

Then, in OJS, use the following as the service URL:

```
http://host.docker.internal:3000/service-url
```

When running the test server, you may also need to add the `--host` flag:

```
npm run start -- --host
```

## Debugging

All deposits are handled by OJS's [jobs queue](https://docs.pkp.sfu.ca/dev/documentation/en/utilities-jobs).

- Regular application errors can be found in the server's error log.
- The plugin creates a log of deposits at `<files_dir>/swordv3.log`.
- The [Status Document](https://swordapp.github.io/swordv3/swordv3.html#9.6) for deposits is saved under the `setting_name` of `swordv3StatusDocument` for each deposited object (`Publication` and `Galley`). It can also be accessed in the CSV exports.
- The plugin treats every `Publication` as a unique object. It has not yet been determined how a SWORDv3 server will link multiple versions of an article together.
- The Reset button can be used to clear all existing Swordv3 deposit data from `Publication` settings. It will not reset the service connection details.
- Re-deposit All will resend the deposit data for all articles that have already been deposited. If an existing deposit object exists, the deposit will replace it. Otherwise, it will create a new deposit object on the swordv3 server.
