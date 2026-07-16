# Workflow System Decision Draft
**Hope Baptist Church Temper – Workflow Definition System**
**Thread:** Workflow System (separate from main dev)
**Started:** July 13, 2026

**Overarching Principle (added per discussion):**  
Workflows are a **means to an auditable system**, not the auditable system itself. They are disposable processes/tools (akin to complex, variable-defined forms). After a transaction is successfully logged in the ledger, the workflow data is no longer maintained/persisted. The ledger entry becomes the permanent record. There is no need to burden the core auditable system with unnecessary workflow data.

This document accumulates decisions as we go through the decision tree. We will revise and refine at the end.

## Decision Area 1: Core Format / Markup Language Choice

### Question Recap
Primary Question: What type of definition language or markup best balances human editability, parsability in PHP, and long-term maintainability for non-developers?

Key Variables: 
- Human readability vs. machine strictness
- Learning curve for church staff (Treasurer or admin)
- Ease of adding comments/documentation inline
- Complexity of validation/parsing code needed
- Support for hierarchical elements (roles → steps → inputs/validations/actions)

Decision Branches:
- Option A: Standardized structured markup (e.g., YAML-like)
- Option B: Lightweight flag/section-based custom format (e.g., marker-driven text)
- Option C: Strict data format (e.g., JSON)
- Option D: Hybrid or another format (Markdown frontmatter, etc.)

Follow-up Questions: How much tolerance do we have for syntax errors breaking a workflow? Should the format natively support comments? What parsing libraries or minimal code are we willing to include?

---
**User Response / Decision:**

I like Option A. Probably YAML. You convinced me that while an easy mode would be good, development and maintaining it would be a nightmare. Anyone that needs to design or modify a workflow can learn some simple YAML markup.

**Recorded Decision:** Proceed with YAML as the standardized structured markup language for workflow definitions.

## Decision Area 2: Structure and Expressiveness Level

### Question Recap
Primary Question: How should the definition express roles, actions, inputs, processing, validation steps, transitions, and final processing?

Key Variables:
- Granularity (flat list vs. nested steps vs. state-machine style)
- Support for conditionals, branching, or loops
- How explicit vs. implicit each element should be (e.g., must every step declare its role, inputs, and post-actions?)
- Ability to reference shared elements (common validations, notification templates, roles)

Decision Branches:
- Minimal/declarative only (simple sequential steps)
- Moderately expressive (basic decisions and side-effects)
- More advanced (full conditions, parallel steps, etc.)

Follow-up Questions: What is the minimum set of elements every workflow must declare to be valid? How do we prevent definitions from becoming too powerful or hard to audit?

---
**User Response / Decision:**

I'm leaning toward more advanced, but wonder if we should start with minimal and expand if necessary. Should we shoot for a graduated implementation or just shoot for the stars? I think minimal/declarative only to start. We can expand it later to more advanced features as we get a feel for its limitations and needs. Maybe at some point we can stage it so the user can draft a quick workflow with the minimal/declarative model and later jump back in and edit the workflow definition with an "advanced" system.

**Recorded Decision:** Start with a **minimal/declarative** model (simple sequential steps) for initial implementation. Plan for future expansion to more advanced expressiveness (conditionals, branching, etc.) once we understand real usage and limitations. Consider a staged/graduated approach where basic workflows can be created simply and later enhanced in an advanced mode.

## Decision Area 3: Governance & Access Control for Definitions

### Question Recap
Primary Question: Who should be allowed to create, edit, activate, or version workflow definitions?

Key Variables:
- Risk level (these control financial processes)
- Separation between definition maintenance and daily usage
- Need for review/approval workflow on changes themselves
- Scalability as the church grows or roles change

Decision Branches:
- Restricted to high-privilege role(s) only (Admin / System Administrator)
- Dedicated “Workflow Manager” or “Process Owner” role(s)
- Treasurer-level access with safeguards
- Broader access with strong versioning and audit logging

Follow-up Questions: Should editing require a separate approval step? How do we handle “live” vs. “draft” definitions?

---
**User Response / Decision:**

Specific role or admin should be the only create/maintain access. Lets focus on the framework, we can implement a versioning system to encompass live/draft workflows later. Lets go with a role for workflow manager and admin as predefined roles with workflow create/edit access. workflow usage access would be assigned during workflow creation so there would need to be hooks into the role system hence the admin/workflow manager access required. this will provide separation from standard limited users and the actual system config users. I would also like a standalone permission entry to be able to assign the create/edit permission to a user outside of a role for dev purposes. Long term intent (think post v1.0) is to remove individual permissions in favor of well hashed out roles.

**Recorded Decision:** Create/edit/maintain access restricted to **Workflow Manager** role and **Admin** role (predefined). Usage/execution permissions assigned per-workflow at creation time (requires role system hooks). Include a temporary standalone permission for dev flexibility. Defer live/draft/versioning details. Long-term: Move toward roles-only, minimize individual permissions.

## Decision Area 4: Editor / User Interface Approach

### Question Recap
Primary Question: What kind of editing experience should exist for managing definitions (in Admin or dedicated Workflows menu)?

Key Variables:
- Technical comfort of intended users
- Implementation effort vs. usability payoff
- Risk of visual tools hiding important logic
- Integration with the rest of the Temper UI (Bootstrap/jQuery context)

Decision Branches:
- Pure text/markup editor (file-based or in-app)
- WYSIWYG / visual flow builder
- Hybrid (text with visual preview or side-by-side)
- No built-in editor initially (file editing + validation only)

Follow-up Questions: Should the editor be part of core MVP or added later? How much does it need to feel “church-friendly”?

---
**User Response / Decision:**

lets not complicate the initial build. as i will be the one designing the majority of the initial workflows and using them I can confidently say that with your help, we could use an external text editor to create the definitions we need and trouble shoot them sufficiently in a development environment before fielding them in any production system. for now, lets go with simplicity so we can accelerate our path to initial release. In future point releases, we can add in workflow manager editor's and create environments. At this time, lets go with a simple import of our externally designed yaml file that uses syntax recognized by temper to create the workflow pathing, actions, permissions, and other requirements.

With the decision to exclude the editor in the initial versions, the follow-up question is moot.

**Recorded Decision:** No built-in editor in initial versions. Use **external text editor** + **simple YAML import** mechanism (Temper recognizes and loads definitions for pathing, actions, permissions, etc.). Prioritize simplicity for faster initial release. Add in-app editors/environments in future releases.

## Decision Area 5: Runtime & Engine Integration

### Question Recap
Primary Question: How should the application load, validate, and execute these definitions?

Key Variables:
- Storage location (files vs. database vs. both)
- Versioning & migration for running instances when definitions change
- Error handling and fallback when a definition is invalid
- Performance (caching loaded definitions)
- Audit trail requirements for every transition

Decision Branches:
- File-based with reload on change
- Database-stored with import/export
- Hybrid

Follow-up Questions: What happens to in-progress workflow instances if their definition is updated? How do we ensure the generic engine stays stable?

---
**User Response / Decision:**

Use the file system to store the definitions exactly as they are created/imported. no modifications should be made by temper to the workflow definitions. If there is an ambiguity or are errors in the workflow there should be a warning on import. The system should validate the definition against actions, permissions, paths, and other requirements from the workflow engine and store the workflow as-is if valid in the file system. This workflow file will be the executable definition of the workflow when called. a checksum can be generated and stored in an index table that can also manage a versioning system. this will allow in-progress workflows to continue to completion. periodically inactive workflows can be archived or deleted since they are not audit-able data or required for audit purposes and only facilitate the management of audit-able data (creating ledger entries. I don't see a problem with caching the workflow definitions but it really isn't necessary at this scale. maybe an admin option later down the road to enable caching but for now, lets table caching. it adds complexity. Internal workflow auditing should be logged in the workflow system tables, but final auditing should be added to document packages that are attached to the audit-able entries. These actions can and will be defined in the workflow definitions, not hard-coded as audit requirements will be different for various types of entries (deposits requiring dual count validations vs expenditures requiring only one validation and fund releases requiring none.)

**Recorded Decision:** Primary storage in **file system** (immutable, exact as imported). Import validation with warnings (no modifications). Executable via original file + checksum/index table in DB for versioning/instance stability. Archive inactive workflows. Table caching for now. Logging in workflow tables + attachable documents for final audit trail. Auditing actions defined per-workflow (not hard-coded).

## Decision Area 6: Long-Term Maintainability & Evolution

### Question Recap
Primary Question: How do we ensure this system remains flexible without creating new technical debt?

Key Variables:
- Testing strategy for definitions
- Documentation needs
- Path for successor handover
- Extensibility (custom actions/hooks)

Decision Branches:
- Emphasize simplicity first
- Build in extensibility points early
- Plan for phased rollout (start with core workflows)

Follow-up Questions: How will we test changes to a workflow safely? What metrics define “good enough” for v1 of this system?

---
**User Response / Decision:**

We will create the engine in full now. The definitions will be created manually for the short term. After we have some use cases and possibly need to modify, we can determine the need for a simpler editor/modification system or if there is even a point. There are finite actions that can take place in the system so we may be able to encompass them all over the dev/beta period and there may not be any need for more than a small settings function for each workflow which we can likely use the definitions to implement. I think for now we are going to focus on the engine so we can create the definition dictionary. Extending the system to a more complex and maintainable internal editor and definition check/suggestion system is a very long term task. prior to v1.0 we will have fleshed out the majority of the workflows and will only need minor editing capabilities (external editor and internal validation) to refine them. This will preclude any complex and heavy systems at release.

**Recorded Decision:** Build the full generic engine now. Manual/external definitions short-term. Focus on engine + definition dictionary first. Finite actions likely covered in dev/beta. Prioritize simplicity and minimalism for v1.0; defer advanced editors/suggestion systems to long-term. Use cases will drive any future editor needs.