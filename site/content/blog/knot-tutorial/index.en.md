+++
date = '2025-04-16'
lastmod = '2025-06-24'
title = 'Knot DNS in a Complex DNSSEC Topology'
tags = ['DNS', 'Knot', 'software']
+++

*Written for the [CZ.NIC Staff Blog](https://en.blog.nic.cz/2025/05/07/knot-dns-in-a-complex-dnssec-topology/)*

[Knot DNS](https://www.knot-dns.cz/) has many powerful and useful features, but
sometimes it might be difficult to see all the intricate ways in which they
interact and complement each other. In this article I'll attempt to clear up
some of that confusion by showcasing a realistic moderately-complex DNS
infrastructure built on instances of Knot. Our focus will be largely on
*DNSSEC*.

## Overview

We'll be setting up the `dook.xdp.cz` zone. For that purpouse I've spun up
several VMs running Debian 12, each with its own IPv4 address. We won't be
enabling IPv6 for this demonstration, but Knot supports it of course.

The topology will be as seen in the image below. Two signer instances, each with
their own set of private keys, sign the zone. On the next layer two validators
verify internal DNSSEC validity. On the third layer are the public facing
nameservers. The `ns1.xdp.cz` nameserver exists independently of us and provides
secured delegation from the parent zone.

![DNS deployment topology graph](knot-topology.svg)

In the image a dotted arrow represents a fallback option, which is only utilized
when the relationship signified by a full arrow is somehow unavailable. So far
so good? Try to keep this schema in your mind as we continue down the rabbit
hole − one layer at a time.

## Layer 1: Multi-signer

In general
[multi-signer](https://www.knot-dns.cz/docs/latest/singlehtml/#dnssec-multi-signer)
is any mode of operation, where a single zone is signed by multiple servers in
parallel. The benefit of a multi-signer setup is increased robustness through redundancy.

The main distinction between multi-signer setups are, whether private keys are
shared between the instances and whether key management is manual or automatic.
In this article we'll go for *automatically managed distinct keys*. This choice
has some implications which need to be carefully considered during configuration.

The most glaring deficiency is, that each signer will in effect be serving a
different zone, since different private keys produce different RRSIGs. For that
reason one of the signers will stay silent, ready to start transmitting its
version of the zone should the primary signer become unavailable. If that occurs
an *AXFR* to the validators is necessary as the primary-backup roles of the
signers become reversed.

The second issue is, that both signers' zones *must* contain each other's
*DNSKEY* records, so that all keys are always properly propagated and recognized
in the DNSSEC chain of trust. Thankfully Knot makes handling this issue simple,
as we'll see.

Here's a basis for configuration of `signer1`, which is, with some minor
edits, usable on `signer2` as well.

```sh
server:
    listen: 172.20.20.175
    automatic-acl: on     # automatically deduce remotes' permissions

remote:
  - id: signer2
    address: 172.20.20.176
  - id: validator1
    address: 172.20.20.177
  - id: validator2
    address: 172.20.20.178

acl:
  - id: signer-keysync
    action: update     # allow DDNS from signer2; necessary for dnskey-sync
    remote: signer2

# parameters for outgoing dnskey-sync
dnskey-sync:
  - id: signer-sync
    remote: signer2
    check-interval: 1m

policy:
  - id: multisigner
    dnskey-management: incremental # don't remove foreign dnssec related RRs
    single-type-signing: on        # use CSK instead of KSK+ZSK
    cds-cdnskey-publish: always    # CDS+CDNSKEY are needed for dnskey-sync
    keytag-modulo: 0/2             # set to "1/2" on signer2 to avoid keytag collisions
    dnskey-sync: signer-sync
    ksk-lifetime: 1M               # ksk-lifetime applies to CSKs
    dnskey-ttl: 1h
    propagation-delay: 10m
    delete-delay: 1d               # keep private keys for some time after DNSKEY removal

zone:
  - domain: dook.xdp.cz.
    acl: signer-keysync
    dnssec-signing: on
    dnssec-policy: multisigner
    serial-policy: unixtime
    serial-modulo: 0/2+180 # set to "1/2" on signer2 to avoid zone serial collisions
    zonefile-sync: -1  # do not write to the zonefile
    zonefile-load: difference-no-serial
    journal-content: all
    notify: [ validator1, validator2 ]
```

Hopefully, with the help of the
[documentation](https://www.knot-dns.cz/docs/latest/singlehtml/index.html),
most of the above should make sense to you by now, but I'll comment on a few of
the options.

`automatic-acl` deduces the correct `acl` rules for remotes based on `master` and
`notify` statements in the `zone` section. Generally any remote registered as a
*master* should have the permission to *notify* us of changes to the zone. By
the same token any remote registered as a *slave* via the `notify` option,
should be able to request a zone transfer.

Usually a *DNSKEY* RR without a corresponding record in Knot's database of keys
is purged from the zone. This behaviour would collide with the *dnskey-sync*
mechanism, so we need to disable it. The disadvantage is, that under certain
circumstances abandoned dnssec-related records may remain in the zone until
removed manually.

The traditional *KSK+ZSK* dnssec model is slowly becoming antiquated since
modern cryptographic algorithms are capable of good security properties with
relatively small keys. In a multi-signer setup, where the number of key-related
records is multiplied by the number of signers, *CSK* is a good fit.

Since we're using the zonefile as a read-only source of data, we must disable
zone flushing via `zonefile-sync: -1` and set `zonefile-load: difference-no-serial`.
The *SOA* serial is ignored and managed internally by Knot, which is important
in our case, as we'll discover later. See
[here](https://www.knot-dns.cz/docs/latest/singlehtml/index.html#handling-zone-file-journal-changes-serials)
for a breakdown of various zonefile operation modes.

Let's also touch on the `(serial|keytag)-modulo` options. As might be
expected, these instruct Knot to only assign values from a certain subset of
natural numbers. This ensures that neither DNSKEY keytags nor zone serial
numbers collide across the two signers. Colliding *keytags* aren't a mission
critical issue, but make key identification difficult for operators and
verification marginally more expensive for resolvers, so it's best to avoid
them. Colliding *serials* on different zones *are likely* to eventually break
your secondaries, be it through faulty IXFRs or other venues, so avoiding them
is essential.

The `+180` portion of `serial-modulo` defines a *serial shift*. The idea is to
set `serial-policy` to `unixtime` and shift one of the signers' serial by a
fixed amount of time. If the primary signer becomes unavailable the secondary
signer will then naturally be put on hold for the period of the serial shift.
Remember that in our case switching masters would mean an expensive AXFR. So
instead of jumping at the opportunity to switch, we give our current master some
time to catch up and send us an *IXFR* instead. If he can't, we fall back and do
the AXFR.

There's also a Knot-specific feature called
[master-pin-tolerance](https://www.knot-dns.cz/docs/latest/singlehtml/index.html#zone-master-pin-tolerance),
which achieves the same effect without the need for shifting serials.

## Layer 2: Validators

The next layer in our little experiment are two
[validators](https://www.knot-dns.cz/docs/latest/singlehtml/index.html#zone-dnssec-validation).
This is another measure to increase robustness. The signer may, either due to
software or operator error, supply a bad zone which the validator would then
prevent from propagating further.

The configuration here is much simpler, since all we do is pass data from one
Knot instance to another:

```sh
server:
    listen: 172.20.20.177
    automatic-acl: on
    dbus-event: dnssec-invalid

remote:
  - id: signer1
    address: 172.20.20.175
  - id: signer2
    address: 172.20.20.176
  - id: ns1
    address: 217.31.192.165
  - id: ns2
    address: 217.31.192.166

zone:
  - domain: dook.xdp.cz.
    dnssec-validation: on
    master: [ signer1, signer2 ]
    notify: [ ns1, ns2 ]
    ixfr-by-one: on
    zonefile-sync: -1
```

This should read simple to any Knot operator, though again there are a few
interesting options.

`dbus-event: dnssec-invalid` ensures that a
[D-Bus event](https://www.knot-dns.cz/docs/latest/singlehtml/#dbus-event) is emitted
on validation failure. This can be used by the server operator to catch and
resolve any issues early.

`ixfr-by-one` tells Knot to partition merged IXFRs back into individual
changes stored separately in the journal. This ensures that the validator will
be able to send an IXFR, even if a slave was previously updated by the other
master.

## Layer 3: Nameservers

Configuring `ns1` and `ns2` is a breeze:

```sh
server:
    listen: [ 0.0.0.0, :: ] # listen on all available addresses v4 and v6
    automatic-acl: on

remote:
  - id: validator1
    address: 172.20.20.177
  - id: validator2
    address: 172.20.20.178

zone:
  - domain: dook.xdp.cz.
    master: [ validator1, validator2 ]
    zonefile-sync: -1
```

### DS records

As we've by now became accustomed to, Knot offers a simple
[solution](https://www.knot-dns.cz/docs/latest/singlehtml/index.html#zone-ds-push)
to publishing DS records in the parent zone:

```sh
# modify signers' configs like so:
remote:
  - id: ns1.xdp.cz.
    address: 217.31.192.165

submission:
  - id: dook-sub
    parent: ns1.xdp.cz.
    check-interval: 30s # if DS submission wasn't confirmed, check again in 30s

policy:
  - id: multisigner
    # [...]
    ksk-submission: dook-sub

zone:
  - domain: dook.xdp.cz.
    # [...]
    ds-push: ns1.xdp.cz.
    policy: multisigner
```

The `submission` section is applied during a certain stage of a
[key rollover](https://knot.pages.nic.cz/knot-dns/master/singlehtml/index.html#dnssec-key-rollovers).
Knot will query the `parent` for relevant *DS* records. If the *DS* record of
the new key is found, it is safe to start removing the old key, if not Knot will
wait for `check-interval` then check again.

`ds-push` is a convenient automatized *DS* publishing mechanism for cases, where
we have *DDNS update* rights in the parent zone. In our case that is true:

```sh
# in ns1.xdp.cz. config:
remote:
  - id: signer1.dook.xdp.cz.
    address: 172.20.20.175
  - id: signer2.dook.xdp.cz.
    address: 172.20.20.176

acl:
  - id: dook-ds-push
    action: update
    remote: [ signer1.dook.xdp.cz., signer2.dook.xdp.cz. ]
    update-type: DS                 # only allow updates of a specific RTYPE
    update-owner: name
    update-owner-match: equal       # only allow updates to a specific owner
    update-owner-name: dook.xdp.cz. # the owner we're allowed to update

zone:
  - domain: xdp.cz.
    acl: dook-ds-push
```

And that's it! Of course this is only possible if you negotiate such
configuration changes with the parent zone's administrator. If that is out of
the question, Knot also supports
[other](https://en.blog.nic.cz/2024/05/10/authenticated-dnssec-bootstrapping-in-knot-dns/)
standardized mechanisms to achieve the same.

## Securing communication

Until now, we left the communication between our servers unprotected, which is a
bad idea. Knot implements 3 ways to rectify this issue: *QUIC*, *TLS* and
*TSIG*.

QUIC and TLS may operate in *strict* or *opportunistic* modes. In strict mode
the peer's public key is verified so that *Man in the Middle* attacks are
prevented.

```sh
server:
    listen-tls: 0.0.0.0
    listen-quic: 0.0.0.0

remote:
  - id: opportunistic
    address: 1.2.3.4
    tls: on
    # or 'quic: on'

  - id: strict
    address: 4.3.2.1
    tls: on
    # or 'quic: on'
    cert-key: US4Q5s598ezu/yKAfAeunIlNnPfu4NSSJHhWCXtpkgY=
```

Strict verification configured in both directions (client to server, server to
client) is considered to be a third mode of operation −
*[mutual](https://www.rfc-editor.org/rfc/rfc9103#name-mutual-tls)*.

*cert-key* is a hash of the public key of the peer TLS certificate (aka *TLS
PIN*). The TLS PIN of the certificate in use can be displayed with `knotc status
cert-key`.

The other option is to use *TSIG*, which doesn't encrypt the communication at
all, but ensures validity of a transaction by attaching a signature made with a
shared secret.

```sh
# generate with 'keymgr -t example-key'
key:
  - id: example-key
    algorithm: hmac-sha256
    secret: lon7UCbCQog+ZqkRO4wBODL3LySrs7fY1Ovm18Bxq6o=

remote:
  - id: tsig
    address: 1.2.3.4
    key: example-key
```

In fact TSIG is orthogonal to the underlying transport protocol, but there isn't
much utility in combining it with either of the two encrypted protocols in their
*strict* mode of operation.

## Wrapping up

*DNSSEC automation* is one of the chief advertised strengths of the Knot DNS
project which, I think, was pleasantly obvious, throughout our experiment. Even
with such a complicated topology, the configuration was *relatively* simple and
didn't require much hackiness of any kind.

We're [always improving](https://gitlab.nic.cz/knot/knot-dns) and always open
to user feedback. If you're looking to report a bug, request a feature or simply
to say *"hi"*, feel free to join one of our
[communication channels](https://www.knot-dns.cz/support/) − we hope to see you
there.
