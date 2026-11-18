# Scheduled publishing (P-3)

A page can be published at a set time, and taken off the site again at another, without anyone pressing a button
then. ADR-0029.

## Who

People who may publish pages (`page.publish`: editors and admins). Contributors don't see the section.

## In the page editor

The **Schedule** section says what is scheduled ("To be published at …", "To be taken off the site at …" or "Nothing
is scheduled.") and has two fields, in **your own time zone** (shown above them; change it in your account):

- **Publish at**: the page has to be **approved**. It is published at that time, or, if it is approved later, as
  soon as it is approved. Not shown once the page is published.
- **Take off the site at**: at that time the page becomes a draft again, if it is published then.

**Save the schedule** keeps them; **Clear the schedule** removes both. The status message repeats the times. A time
in the past, an end before the start, or a time that doesn't exist (the night the clocks go forward) is explained,
and the form keeps what you typed. On the night the clocks go back, a time that happens twice means the second one.

The page list shows the schedule next to the state, as words: "Approved · To be published at 1 Dec 2026, 09:00".

## What happens at the time

`bin/sightpress schedule:run`, which runs once a minute (see the skeleton's scheduler guide), publishes the page within
a minute of its time, **as the person who scheduled it**: the same publish checks run, and they must still be allowed
to publish. If something stops it (a check fails, they may no longer publish), nothing is published, the audit log
says why ("Couldn’t publish “…” on schedule: …") and that time is cleared so you can fix the page and schedule it again.

If the scheduler wasn't running at the time (the server was off), the page is published on its next run. Archiving
a page or putting it in the trash clears its schedule.

## Command line

`bin/sightpress schedule:run` does what is due on every enabled site and prints a line per page ("demo: Published
“Winter concert” on schedule.") and a summary. Two runs never overlap: the second says "Another run is still going;
this one stops." Other modules add their own jobs to the same run.

## Audit log

`page.scheduled` (set or cleared, times in UTC), `page.publish` / `page.unpublish` with the note "Published on
schedule." / "Taken off the site on schedule.", and `page.schedule_failed`.
