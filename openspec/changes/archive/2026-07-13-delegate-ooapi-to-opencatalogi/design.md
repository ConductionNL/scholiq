# Design: delegate-ooapi-to-opencatalogi

Size S — this is a contract/spec-consistency change, not a build. Design content is kept to the one
decision worth writing down: the field-mapping table and why the target field needed no schema change.

## Field mapping (OOAPI 5.0 ↔ Scholiq ↔ RIO)

| OOAPI 5.0 resource | Scholiq object | Key Scholiq fields | RIO model (keyed when present) |
|---|---|---|---|
| `course` | `Course` (`lib/Settings/scholiq_register.json`) | `code`, `name`, `name_nl`, `description`, `level`, `language` | `opleidingseenheid` |
| `program` | `Programme` | `name`, `code`, `level`, `description`, `courseIds` | `aangeboden opleiding` |
| `offering` | `Cohort` | `programmeId`/`courseId`, `period`, `academicYear`, `teacherIds`, `learnerIds` | `aangeboden opleiding` (per-run instance) |

`Course.level`/`Programme.level` already use the enum `po|vo|mbo|hbo|wo|corporate`, which the OOAPI/RIO
mapping in openconnector will need to translate to OOAPI's education-level vocabulary — that translation
table is openconnector's concern, not scholiq's; scholiq's contract obligation stops at "here is the level
value and here is what it means."

Neither `Course` nor `Programme` nor `Cohort` currently has a RIO identifier field. This change does not add
one — RIO keying is described as "when the institution has recorded them" because most PO/VO/MBO-corporate
tenants have no RIO registration at all (RIO is a HBO/WO-centric register per `nl_standards` 521). Adding an
optional `rioId`-style field to `Course`/`Programme` is left to whichever change actually implements the
openconnector adapter, once a real consuming institution needs it — speculative field addition here would
violate "minimal or no code" for a change with no code to test it against.

## Why `DataExchangeJob.target` needed no migration

`target` (`lib/Settings/scholiq_register.json` → `DataExchangeJob.properties.target`) is `type: string` with
a free-text description listing examples (`bron-rod`, `oso`, `leerplicht`, `surfconext`, `hr`) — not a
closed `enum`. `ooapi-catalog` is therefore a valid value today; the only change is documentation (the
description text gets the new example appended) so implementers discover the convention instead of
inventing their own target name.

## Rejected alternatives

- **Scholiq serves `/ooapi/v5/*` itself** (the status quo `course-management` requirement). Rejected: directly
  contradicts `data-exchange`'s existing "Delegate wire protocols to OpenConnector" requirement, which
  already names OOAPI in the forbidden list; would require a new controller class doing exactly the kind of
  wire-protocol work every other external standard (BRON/ROD, OSO, Digikoppeling, SURFconext) explicitly
  avoids in Scholiq.
- **Add a new `CatalogPublication` schema in Scholiq to track publication state separately from
  `lifecycle`.** Rejected: `Course`/`Programme` already have a `lifecycle` field with `publish`/`archive`
  transitions and a `published` notification wired via `x-openregister-notifications` — reusing it avoids a
  second source of truth for "is this published."
- **Model `offering` as a new schema instead of reusing `Cohort`.** Rejected: `Cohort` already carries
  exactly the shape OOAPI's `offering` resource needs (a specific run of a course/programme, with period,
  academic year, and enrolled/teaching participants) — a second schema would duplicate data the app already
  persists.
