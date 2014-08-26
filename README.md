OpenConext Stoker
=================
![Image of a stoker putting coal into an engine](http://upload.wikimedia.org/wikipedia/commons/thumb/2/22/Baureihe52Heizer.jpg/203px-Baureihe52Heizer.jpg)
> Fireman or stoker is the job title for someone whose job is to tend the fire for the running of a steam engine.
>
> On steam locomotives the term fireman is usually used, while on steamships and stationary steam engines, such as those driving saw mills, the term is usually stoker (although the British Merchant Navy did use fireman). The German word Heizer is equivalent. Much of the job is hard physical labor, such as shoveling fuel, typically coal, into the engine's firebox.
- [Wikipedia: Stoker (occupation)](http://en.wikipedia.org/wiki/Stoker_\(occupation\))

The job of OpenConext stoker is to take a SAML2 metadata XML file and synchronise it with an internal cache used by
OpenConext EngineBlock StokerRepository.

It's intent is to:
- Increase performance over downloading metadata xml per-request.
- Reduce dependency on uptime of destination metadata xml.
- Allow for an extension point to 'massage' metadata.

## Usage
```bash
ocstoker http://mds.edugain.org /var/cache/openconext/stoker --certPath=https://www.edugain.org/mds-2014.cer
```
Synchronises the SAML2 Metdata at mds.edugain.org to /var/cache/openconext/stoker.

## Install from build
```bash
cd /usr/local/bin && wget https://github.com/ibuildingsnl/OpenConext-stoker/releases/
```

## Install from source
```bash
git clone 

```

# Known issues
We currently only support signed RSA-SHA1 metadata documents, pull requests welcome!