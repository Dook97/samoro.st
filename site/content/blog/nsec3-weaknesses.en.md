+++
date = '2025-04-02T20:14:49+01:00'
lastmod = '2025-04-22'
title = 'On the Drawbacks, Weaknesses and Appropriate Uses of NSEC3'
+++

*Written for the [CZ.NIC staff blog.](https://en.blog.nic.cz/2025/04/02/on-the-drawbacks-weaknesses-and-appropriate-uses-of-nsec3/)*

Let's start with a brief reminder of non-existence proofs in DNSSEC. If you
have a solid understanding of the topic, feel free to skip this introduction.

The standard DNSSEC solution to proving a record's non-existence is the NSEC
RR. It contains the next node in the lexicographical order and a bitmask of
available RTYPEs:

```example. 300 IN NSEC ns1.example. A NS SOA RRSIG NSEC DNSKEY```

In the trivial case, the node exists but lacks a RRSET of the queried type.
This is easily verified with the type bitmap.

If the node doesn't exist the server replies with the preceding node's NSEC.
Since RDATA specify the name that immediately follows, we can check whether
our query falls inbetween these two names. If it does there's our non-existence
proof − nothing may appear between immediate neighbours in the lexicographic
ordering.

A neat trick, right? Well, many would disagree. While elegant the solution
makes zone extraction very easy. A curious client may simply walk along the
chain of NSECs requesting whatever RRSETs are indicated as present in the types
bitmap. Eventhough all data obtained in this way is in a sense public, many
operators would prefer an added layer of security by obscurity.

NSEC3 records are meant to mitigate the enumeration issue by hashing the
labels:

```${HASH}.example. 300 IN NSEC3 1 0 0 - ${NEXT_HASH} A RRSIG```

Here the RDATA structure is a bit more complicated:

1. hashing algorithm identifier (0 = reserved, 1 = SHA-1, 2-255 = not assigned)
1. flags field (currently only signals opt-out)
1. additional hashing iterations (0-65535)
1. hashing salt (0-255 hex encoded octets; hyphen indicates empty salt)
1. next NSEC3 hash in lexicographical order
1. a bitmap of RTYPEs present in the node

The general idea is much the same, except now the labels are salted, hashed and
the NSEC3 records may not be requested directly. This prevents the
straightforward chain walking which was possible with the simpler NSECs and
even if we recover the NSEC3 chain, all we have is a bundle of SHA-1 hashes.

However readers possessing a passing knowledge of cybersecurity might suspect
this protection to be weak. Readers still more seasoned in these topics might
even see a few different ways in which the solution actually makes DNS *less*
secure and resilient to various kinds of attacks.

## Hashing as a defense against zone enumeration

Cracking a hash is only as difficult as guessing the input from which it was
generated and in the case of DNS that is mostly pretty easy. Labels are selected
on the basis of convenience and long random names are largely undesirable.

In other domains secrets are usually salted to prevent attacks by precomputed
rainbow tables, this benefit however doesn't apply here since hashes are
already implicitly salted by the zone's FQDN. A non-empty salt therefore bears
no security benefits except when it changes during the chain walking stage of
an attack. In that case the attacker would be left with an incomplete chain.
For that to be a functional defense however, all NSEC3s and their RRSIGs would
need to be recomputed with impractical frequency.

## Practical zone mining examples

To illustrate some of the above mentioned issues we decided to attempt
enumeration of nic.cz. I did not have any special prior knowledge of the zone,
neither did I access its contents via means unavailable to the general public.
All cracking was done on an AMD EPYC 7702P CPU.

At the time of the attack the zone was configured to use NSEC3 with an 8B salt
and 0 additional hashing iterations.

### Phase 1: Walking the Chain

Extracting the NSEC3 chain is the online phase of the attack. Unlike with the
simpler NSECs, we can't sequentially request every NSEC3 record in the chain
based off the RDATA of the previous one. Instead we can generate queries which
fall into various intervals within the chain gradually revealing it.

As usual libre software comes with a ready made
[solution](https://github.com/vitezslav-lindovsky/nsec3walker).

```
$ nsec3walker nic.cz >hashes.txt
```

In a few seconds we get 1044 hashes − the entire NSEC3 chain − along with a csv
file mapping them to their available RTYPEs. The tool is fairly efficient with
network requests allowing even for enumeration of large zones without clashing
with rate limiting mechanisms.

### Phase 2: Offline Cracking

Now to the interesting bit − offline cracking with hashcat. Hashcat is a
powerful, if at first a bit overwhelming, free software tool designed for
breaking various kinds of encryption. It can be found in the standard toolbox
of many security researchers (as well as "security researchers").

The output of nsec3walker is already conveniently formatted for use with
hashcat's NSEC3 module. Each line contains all information needed for cracking:
the hash, salt and number of iterations. Note the missing dot after the TLD
name − hashcat's NSEC3 module has no respect for proper notation.

```
q0kjrc4rooao94qphcttgrn3vrvsohfd:.nic.cz:0bbdde64aecf8344:0
```

Starting with the simplest and reasonably effective option: dictionary attacks.
For our purposes a few different dictionaries were utilized:

* a general DNS [wordlist](https://github.com/esetal/wordlists/raw/refs/heads/main/best-dns-wordlist.txt.zip) (~9.5mil entries)
* a Czech language [wordlist](https://gpsfreemaps.net/files/security/wordlist/CZ.7z) (~7mil entries)
* the .sk ccTLD [domain names list](https://sk-nic.sk/subory/domains.txt) (~0.5mil entries)

We feed them to hashcat like:

```
$ cat dns.txt czech.txt sk.txt >combined.txt
$ hashcat -a0 -w4 -O -m8300 hashes.txt combined.txt
```

...and in a few seconds recover 196 hashes which make up about 19% of our hash
database. Not bad! Now let's take a step back and look at the command invocation.

The options specify in order:

* the attack mode (0 = dictionary)
* how much system resources may be utilized (4 = most permissive)
* to utilize optimized data structures and algorithms, with the drawback of
  shorter maximum candidate length
* the hash type (8300 = NSEC3)

But where did our cracked data go? To prevent duplication of efforts, hashcat
stores all cracked hashes in a special file called the potfile somewhere in the
depths of your $HOME directory. If you cracked a hash in one session it will be
automatically discarded if detected in the input of another one.

If you want a list of all secrets cracked so far you can always get them like so:

```
$ hashcat -m8300 --show hashes.txt

q0kjrc4rooao94qphcttgrn3vrvsohfd:.nic.cz:0bbdde64aecf8344:0:akademie
[...]
```

We tried the smart and easy approach, how about doing something difficult and
dumb for a change?

```
$ hashcat -a3 -w4 -O -m8300 --increment -1 '?l?d-.' hashes.txt '?1?1?1?1?1?1?1?1'
```

The brute force attack. We define charset 1 as all lowercase letters, numbers,
dash and dot then tell hashcat to try all combinations of these up to the length
of 8. This took about 2.5 hours and recovered another 165 hashes.

These attack types can be combined in various ways. We may concatenate entries
from two dictionaries, bruteforce a prefix or suffix to the entries or use the
already discovered subdomains in combination with these methods. For example:

```
$ hashcat -a1 -w4 -O -m8300 -j'$.' hashes.txt wordlist.txt discovered.txt
```

Prepends a dictionary entry followed by a dot to every previously discovered vertex.

The zone also usually leaks through other channels. For example: once we have
uncovered a fair amount of domains we may query for NS, TXT, CNAME and DNAME
records or make reverse queries on addresses from the host's range.

Using these methods I've been able to uncover about 84% of the hashes in 3 days
and 6 hours of compute time. A more experienced operator could've reduced the
time significantly with similar results.

Several facts to consider:

* I am no pentester or security specialist; in fact this was my first time
  using a security auditing tool like hashcat.
* The dictionaries I used were sourced through a few minutes of Googling and
  weren't necessarily well optimized for the task.
* All cracking was done on a, albeit powerful, CPU instead of a GPU, which
  could potentially be an order of magnitude faster.
* From our experiments with other zones the 84% figure seems to be on the lower
  end of what is easily achievable, with our biggest success being a zone with
  several hundred vertices mined to 97% in less than a day of compute time.

To see how much of an impact additional iterations have on computational
complexity we repeated the same process for the nix.cz zone, which, at the
time of writing, is configured with a 20B salt and **50 additional iterations**.

For my specific workload the slowdown was in the range of 6-8.5x of the
equivalent with 0 added iterations. In about two days of compute time I've
recovered 78% of the 178 hashes. I'm confident we could've gotten over 90% were
we willing to expend the CPU cycles.

A dubious but interesting benefit of this configuration is that hashcat's NSEC3
module is currently limited to a maximum of 16B for salt. However the limit
seems to be entirely arbitrary and may be [easily
increased](https://github.com/hashcat/hashcat/pull/4157) by anyone not afraid
of compiling their own software.

## Troubles with iterated hashing

NSEC3 attempts to make cracking harder by introducing the "additional hashing
iterations" parameter. The downside of course being, that while any attacker
trying to uncover the chain has to expend more CPU cycles, so do the resolvers
and authoritative servers. A large asymmetry between the costs of making a
bogus query and providing an answer is introduced opening doors to
amplification DoS attacks.

| Iterations | QPS [% of 0 Iterations QPS] |
| ---------- | --------------------------- |
| 0          | 100%                        |
| 10         | 89%                         |
| 20         | 82%                         |
| 50         | 64%                         |
| 100        | 47%                         |
| 150        | 38%                         |

Impact of NSEC3 hashing iterations on the response capacity of authoritative
servers [(src)](https://www.rfc-editor.org/rfc/rfc9276.html#name-computational-burdens-of-pr)

For resolvers this has become such a problem many will refuse to validate
responses with a high enough hash iteration parameter. In the future the limits
of what they're willing to accept should gradually decrease as more zones
become compliant with the [recommended NSEC3 parameters](https://www.rfc-editor.org/rfc/rfc9276.html#section-3.1-3).

| Resolver          | Iteration limit | Salt limit (octets)   |
| ----------------- | --------------- | --------------------- |
| Bind9             | 50              | none                  |
| Knot Resolver     | 50              | depends on iterations |
| PowerDNS Recursor | 50              | 150                   |
| Unbound           | 150             | none                  |

To see how that translates into practice let's make a query to a misconfigured
zone using the odvr.nic.cz service powered by Knot Resolver:

```
$ kdig @odvr.nic.cz. +dnssec xxx.bad-nsec3.xdp.cz.

;; ->>HEADER<<- opcode: QUERY; status: NXDOMAIN; id: 62118
;; Flags: qr rd ra; QUERY: 1; ANSWER: 0; AUTHORITY: 6; ADDITIONAL: 1

;; EDNS PSEUDOSECTION:
;; Version: 0; flags: do; UDP size: 1232 B; ext-rcode: NOERROR
;; EDE: 27 (Unsupported NSEC3 Iterations Value): 'AUO2'

;; QUESTION SECTION:
;; xxx.bad-nsec3.xdp.cz.                IN      A

;; AUTHORITY SECTION:
[...]
dihp16q4nntlp1a02gq887socmkg7jsi.bad-nsec3.xdp.cz. 300 IN NSEC3 1 0 51 B641CEE1 4bkhig49ffgq97c1obvdn3bn3u9864m4 NS SOA RRSIG DNSKEY NSEC3PARAM
4bkhig49ffgq97c1obvdn3bn3u9864m4.bad-nsec3.xdp.cz. 300 IN NSEC3 1 0 51 B641CEE1 9sqvqqe2ug8uies4f5isubce89g7md86 TXT RRSIG
[...]
```

Notice that the value of the additional iterations parameter is set to 51 and
consequently that the AD flag is missing indicating an unverified response.
Resolvers are also allowed to respond with SERVFAIL in such cases.

## Proper usage of NSEC3

We've been pretty harsh on NSEC3 in this article, but still it has a very
important function in the world of contemporary DNSSEC. Unlike its more
rudimentary older brother, NSEC3 allows for DNSSEC opt-out which is vital for
incremental DNSSEC deployment in large zones with many insecure delegations.
The already gigantic .com zone, for example, would become even more
unmanageably large if it wasn't for the option of opt-out.

Any dubious benefits of security by obscurity are made almost completely null
with moderately powerful hardware even in the hands of a complete amateur.
NSEC3 shouldn't be considered on the merits of its security properties.

[RFC 9276](https://www.rfc-editor.org/rfc/rfc9276.html#section-3.1-3)
recommends using 0 additional hashing iterations and an empty salt. For small
zones, or generally for zones without the need for DNSSEC opt-out, using the
computationally less demanding NSEC should be prefered, since even without any
additional hashing iterations NSEC3 proofs are considerably more expensive.
