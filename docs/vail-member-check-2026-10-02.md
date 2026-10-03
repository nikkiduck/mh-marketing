# Vail Board of REALTORS member records: Anyprop vs Spark, 2026-10-02

Checked at about 23:35 UTC on 2026-10-02 from the marketing server, live
against both APIs. Both feeds come from the Vail MLS (VMLS). The old Spark
feed is the one monthausint.com used until 2026-10-01; Anyprop is the feed
monthaus.com and the marketing portal use now.

## Mont Haus International Realty, Vail office

Anyprop (OriginatingSystemName `vbor`, OfficeMlsId `oaltixrealty`) and Spark
(OfficeKey `20220505163801419347000000`) return the same five active members:

| MLS ID | Member | Email | Last modified (Spark) |
|---|---|---|---|
| 21876 | Allison Decent | allison.decent@monthaus.com | 2026-08-12 |
| 569 | Lynn Emmert | lynn.emmert@monthaus.com | 2026-08-07 |
| 49049 | Jean-Michel Drai | jm.drai@monthaus.com | 2026-05-28 |
| 49181 | Bryan Cournoyer | bryan.cournoyer@monthaus.com | 2026-05-19 |
| 49693 | Olivia Roemer | olivia@avetransactions.com | 2026-05-15 |

## Jonathan Boxer on the Vail board

Both feeds carry one record for jonathan.boxer@monthaus.com:

| Field | Anyprop | Spark |
|---|---|---|
| MemberMlsId | 49179 | 49179 |
| MemberFullName | Jonathan Scott Boxer | Jonathan Scott Boxer |
| MemberStatus | Active | Active |
| OfficeName | Christie's International Real Estate Colorado | Christie's International Real Estate Colorado |
| OfficeMlsId | oCIREC | oCIREC |
| ModificationTimestamp | (not requested) | 2026-05-06T22:01:10Z |

So the two feeds agree: in the Vail MLS's own member data Jonathan is still
licensed under Christie's, last changed 2026-05-06. Anyprop is not behind;
the transfer to Mont Haus has not been recorded at VBOR.

## Telluride (for the same conversation)

Anyprop's Telluride feed (`tridemls`) has no office whose name contains
"Mont" other than two Coldwell Banker Montrose offices, no member record for
jonathan.boxer@monthaus.com, and no listings under a Mont Haus office. There
was never a Spark feed for Telluride to compare against.

## How this was checked

Member queries on both APIs, read-only:

- Anyprop: `/v1/listings/data/Member?$filter=OriginatingSystemName eq 'vbor' and MemberEmail eq '…'`
  and `… and OfficeMlsId eq 'oaltixrealty'`
- Spark: `/Version/3/Reso/OData/Member?$filter=MemberEmail eq '…'` and
  `$filter=OfficeKey eq '2022…' and MemberStatus eq 'Active'` with the Vail token
