OpenConext Stoker
=================
![Image of a stoker putting coal into an engine](http://upload.wikimedia.org/wikipedia/commons/2/22/Baureihe52Heizer.jpg)
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
ocstoker stoke http://mds.edugain.org /var/cache/openconext/stoker/edugain --certPath=https://www.edugain.org/mds-2014.cer
```
Synchronises the SAML2 Metdata at mds.edugain.org to /var/cache/openconext/stoker.

## Install from build
```bash
cd /usr/local/bin && 
sudo wget https://github.com/ibuildingsnl/OpenConext-stoker/releases/download/0.3.2/ocstoker.phar && 
sudo ln -s ocstoker.phar ocstoker 
```

## Install from source
```bash
git clone git@github.com:ibuildingsnl/OpenConext-stoker.git &&
cd OpenConext-stoker &&
composer install &&
./bin/ocstoker
```

# Output format
We output 2 things:

1. The index, which contains just a list of all the entities along with some normalised data.
2. Per entity the relevant SAML2 EntityDescriptor.

Further described hereafter.

## 1. The Index
The Index always stored at ```metadata.index.json``` contains the following information:
* ```processed```: ISO 8601 datetime that these entities were processed.
* ```entities```: Array with all entities, per entity:
  * ```entityId```: SAML2 Entity ID for the entity
  * ```types```: array with either "idp", "sp" or both.
  * ```displayNameNl```: Normalised Dutch display name.
  * ```displayNameEn```: Normalised English display name.
* ```cacheUntil```: SAML2 Metadata cacheUntil, how long we should cache (typically shorter than the validUntil).
* ```validUntil```: SAML2 Metadata validUntil, after which time we may not use this metadata any more.

### Example
```json
{
  "processed": "2014-09-12T16:22:16+02:00",
  "entities": [
    {
      "entityId": "
      ",
      "types": [
        "idp"
      ],
      "displayNameNl": "Technische Universiteit Eindhoven",
      "displayNameEn": "Eindhoven University of Technology"
    }
  ],
  "cacheUntil": "2014-09-12T20:22:16+00:00",
  "validUntil": "2014-09-16T14:20:04+00:00"
}
```

## 2. The entities

The SAML2 EntityDescriptor from the original metadata is stored as is in a file whose name corresponds to the MD5 hash of the entityId and ends in '.xml'.

### Example
From ```094f85b774f9b4334638677b70d5755c.xml``` (094f85b774f9b4334638677b70d5755c being the MD5 hash of http://adfs.tue.nl/adfs/services/trust):

```xml
<md:EntityDescriptor xmlns:md="urn:oasis:names:tc:SAML:2.0:metadata" entityID="http://adfs.tue.nl/adfs/services/trust">
    <md:Extensions>
      <mdrpi:RegistrationInfo xmlns:mdrpi="urn:oasis:names:tc:SAML:metadata:rpi" registrationAuthority="http://www.surfconext.nl/" registrationInstant="2013-03-20T12:22:05Z">
        <mdrpi:RegistrationPolicy xml:lang="en">https://wiki.surfnetlabs.nl/display/eduGAIN/EduGAIN</mdrpi:RegistrationPolicy>
      </mdrpi:RegistrationInfo>
    </md:Extensions>
    <md:IDPSSODescriptor protocolSupportEnumeration="urn:oasis:names:tc:SAML:2.0:protocol">
      <md:Extensions>
        <mdui:UIInfo xmlns:mdui="urn:oasis:names:tc:SAML:metadata:ui">
          <mdui:DisplayName xml:lang="nl">Technische Universiteit Eindhoven</mdui:DisplayName>
          <mdui:DisplayName xml:lang="en">Eindhoven University of Technology</mdui:DisplayName>
          <mdui:Description xml:lang="nl">Technische Universiteit Eindhoven</mdui:Description>
          <mdui:Description xml:lang="en">Technische Universiteit Eindhoven</mdui:Description>
          <mdui:Logo height="60" width="120">https://static.surfconext.nl/media/idp/tue.png</mdui:Logo>
          <mdui:Keywords xml:lang="nl">Eindhoven University of Technology Technische Universiteit Eindhoven TUE</mdui:Keywords>
          <mdui:Keywords xml:lang="en">Eindhoven University of Technology Technische Universiteit Eindhoven TUE</mdui:Keywords>
        </mdui:UIInfo>
      </md:Extensions>
      <md:KeyDescriptor xmlns:ds="http://www.w3.org/2000/09/xmldsig#" use="signing">
        <ds:KeyInfo>
          <ds:X509Data>
            <ds:X509Certificate>MIID3zCCAsegAwIBAgIJAMVC9xn1ZfsuMA0GCSqGSIb3DQEBCwUAMIGFMQswCQYD
VQQGEwJOTDEQMA4GA1UECAwHVXRyZWNodDEQMA4GA1UEBwwHVXRyZWNodDEVMBMG
A1UECgwMU1VSRm5ldCBCLlYuMRMwEQYDVQQLDApTVVJGY29uZXh0MSYwJAYDVQQD
DB1lbmdpbmUuc3VyZmNvbmV4dC5ubCAyMDE0MDUwNTAeFw0xNDA1MDUxNDIyMzVa
Fw0xOTA1MDUxNDIyMzVaMIGFMQswCQYDVQQGEwJOTDEQMA4GA1UECAwHVXRyZWNo
dDEQMA4GA1UEBwwHVXRyZWNodDEVMBMGA1UECgwMU1VSRm5ldCBCLlYuMRMwEQYD
VQQLDApTVVJGY29uZXh0MSYwJAYDVQQDDB1lbmdpbmUuc3VyZmNvbmV4dC5ubCAy
MDE0MDUwNTCCASIwDQYJKoZIhvcNAQEBBQADggEPADCCAQoCggEBAKthMDbB0jKH
efPzmRu9t2h7iLP4wAXr42bHpjzTEk6gttHFb4l/hFiz1YBI88TjiH6hVjnozo/Y
HA2c51us+Y7g0XoS7653lbUN/EHzvDMuyis4Xi2Ijf1A/OUQfH1iFUWttIgtWK9+
fatXoGUS6tirQvrzVh6ZstEp1xbpo1SF6UoVl+fh7tM81qz+Crr/Kroan0UjpZOF
TwxPoK6fdLgMAieKSCRmBGpbJHbQ2xxbdykBBrBbdfzIX4CDepfjE9h/40ldw5jR
n3e392jrS6htk23N9BWWrpBT5QCk0kH3h/6F1Dm6TkyG9CDtt73/anuRkvXbeygI
4wml9bL3rE8CAwEAAaNQME4wHQYDVR0OBBYEFD+Ac7akFxaMhBQAjVfvgGfY8hNK
MB8GA1UdIwQYMBaAFD+Ac7akFxaMhBQAjVfvgGfY8hNKMAwGA1UdEwQFMAMBAf8w
DQYJKoZIhvcNAQELBQADggEBAC8L9D67CxIhGo5aGVu63WqRHBNOdo/FAGI7LURD
FeRmG5nRw/VXzJLGJksh4FSkx7aPrxNWF1uFiDZ80EuYQuIv7bDLblK31ZEbdg1R
9LgiZCdYSr464I7yXQY9o6FiNtSKZkQO8EsscJPPy/Zp4uHAnADWACkOUHiCbcKi
UUFu66dX0Wr/v53Gekz487GgVRs8HEeT9MU1reBKRgdENR8PNg4rbQfLc3YQKLWK
7yWnn/RenjDpuCiePj8N8/80tGgrNgK/6fzM3zI18sSywnXLswxqDb/J+jgVxnQ6
MrsTf1urM8MnfcxG/82oHIwfMh/sXPCZpo+DTLkhQxctJ3M=
</ds:X509Certificate>
          </ds:X509Data>
        </ds:KeyInfo>
      </md:KeyDescriptor>
      <md:NameIDFormat xmlns:mdui="urn:oasis:names:tc:SAML:metadata:ui">urn:oasis:names:tc:SAML:2.0:nameid-format:persistent</md:NameIDFormat>
      <md:NameIDFormat xmlns:mdui="urn:oasis:names:tc:SAML:metadata:ui">urn:oasis:names:tc:SAML:2.0:nameid-format:transient</md:NameIDFormat>
      <md:SingleSignOnService Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect" Location="https://engine.surfconext.nl/authentication/idp/single-sign-on/094f85b774f9b4334638677b70d5755c"/>
    </md:IDPSSODescriptor>
    <md:Organization>
      <md:OrganizationName xml:lang="nl">Technische Universiteit Eindhoven</md:OrganizationName>
      <md:OrganizationName xml:lang="en">Technische Universiteit Eindhoven</md:OrganizationName>
      <md:OrganizationDisplayName xml:lang="en">Technische Universiteit Eindhoven</md:OrganizationDisplayName>
      <md:OrganizationURL xml:lang="en">http://www.surffederatie.nl</md:OrganizationURL>
    </md:Organization>
    <md:ContactPerson contactType="administrative">
      <md:GivenName>SURFconext-beheer</md:GivenName>
      <md:EmailAddress>SURFconext-beheer@surfnet.nl</md:EmailAddress>
    </md:ContactPerson>
    <md:ContactPerson contactType="technical">
      <md:GivenName>SURFconext-beheer</md:GivenName>
      <md:EmailAddress>SURFconext-beheer@surfnet.nl</md:EmailAddress>
    </md:ContactPerson>
    <md:ContactPerson contactType="support">
      <md:GivenName>SURFconext support</md:GivenName>
      <md:EmailAddress>help@surfconext.nl</md:EmailAddress>
    </md:ContactPerson>
  </md:EntityDescriptor>
```


# Development: Building a new release
This project uses Box (v2.4) to package everything up into a phar, follow the global install instructions on [the Box project website](https://github.com/box-project/box2) and run ```box build``` in the project root to build a new release.
**Don't forget to tag the project first!**.

# Known issues
We currently only support signed RSA-SHA256 metadata documents, pull requests welcome!
