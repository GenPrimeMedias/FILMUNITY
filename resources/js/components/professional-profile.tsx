import { SearchableSelect } from '@/components/searchable-select';
import { PassportPhotoUpload } from '@/components/individual-membership-panel';
import ProfileHeroPreview from '@/components/profile-hero-preview';
import { router } from '@inertiajs/react';
import { useEffect, useRef, useState, type FormEvent } from "react";
import roleCatalog from "@/lib/professional-role-catalog.json";
import styles from "./professional-profile.module.css";

const industries = ["Tamil", "Malayalam", "Telugu", "Kannada"];
const roles = [...Object.values(roleCatalog.groups).flat(), ...roleCatalog.legacy];
const draftKey = "filmunity.profile-preview.v1";
const steps = ["Industry & roles", "Basic info", "Photo & banner", "Work bio & links", "Portfolio", "Hero slider", "Review showcase"];
const reviewStep = steps.length - 1;
const heroSliderStep = reviewStep - 1;
const portfolioStep = heroSliderStep - 1;
const heroSlideLimit = 10;
const heroSlideMaxBytes = 2 * 1024 * 1024;
const heroSlideSavedMaxBytes = 256 * 1024;
const profileImageTypes = ["image/jpeg", "image/png", "image/webp"];

function readDataUrl(file: Blob): Promise<string> {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(String(reader.result));
    reader.onerror = () => reject(reader.error);
    reader.readAsDataURL(file);
  });
}
const projectTypes = ["Feature film", "Short film", "Series", "Documentary", "Music video", "Commercial", "Other"];
const portfolioLimit = 6;

async function prepareHeroSlide(file: File): Promise<string> {
  const image = await createImageBitmap(file);
  try {
    if (file.size <= heroSlideSavedMaxBytes) {
      return await readDataUrl(file);
    }

    const canvas = document.createElement("canvas");
    const context = canvas.getContext("2d");
    if (!context) throw new Error("Image processing is unavailable.");

    const longestEdge = Math.max(image.width, image.height);
    for (const edge of [1920, 1440, 1080, 810, 608, 456, 342, 256]) {
      const scale = Math.min(1, edge / longestEdge);
      const width = Math.max(1, Math.round(image.width * scale));
      const height = Math.max(1, Math.round(image.height * scale));
      if (canvas.width === width && canvas.height === height && edge !== 1920) continue;

      canvas.width = width;
      canvas.height = height;
      context.drawImage(image, 0, 0, width, height);

      for (const quality of [0.86, 0.72, 0.55]) {
        let encoded = await new Promise<Blob | null>((resolve) => canvas.toBlob(resolve, "image/webp", quality));
        if (!encoded || encoded.type !== "image/webp") {
          encoded = await new Promise<Blob | null>((resolve) => canvas.toBlob(resolve, "image/jpeg", quality));
        }
        if (encoded && encoded.size <= heroSlideSavedMaxBytes && profileImageTypes.includes(encoded.type)) {
          return await readDataUrl(encoded);
        }
      }
    }

    throw new Error("Could not optimize this slide.");
  } finally {
    image.close();
  }
}

type PortfolioProject = {
  id: string;
  title: string;
  role: string;
  type: string;
  year: string;
  url: string;
  contribution: string;
};
const usernamePattern = /^[a-z0-9_]{3,30}$/;

function normalizeUsername(value: string) {
  return value.trim().replace(/^@/, "").toLowerCase();
}

export const socialPlatforms = {
  instagram: "Instagram",
  youtube: "YouTube",
  linkedin: "LinkedIn",
  imdb: "IMDb",
  facebook: "Facebook",
  x: "X",
} as const;

const socialKeys = Object.keys(socialPlatforms) as (keyof typeof socialPlatforms)[];

type Profile = {
  name: string;
  username: string;
  industry: string;
  additional: string[];
  role: string;
  photo: string;
  banner: string;
  heroSlides: string[];
  socialLinks: Partial<Record<keyof typeof socialPlatforms, string>>;
  bio: string;
  social: string;
  portfolio: PortfolioProject[];
  headline: string;
  specialisations: string;
  languages: string;
  city: string;
  travel: string;
  availability: string;
  showWorkPreferences: boolean;
  highlights: string;
  training: string;
  memberships: string;
  openToEnquiries: boolean;
  showreel: string;
  featuredProjectId: string;
};

const empty: Profile = {
  name: "", username: "", industry: "", additional: [],
  role: "", photo: "", banner: "", heroSlides: [], socialLinks: {}, bio: "", social: "", portfolio: [],
  headline: "", specialisations: "", languages: "", city: "", travel: "",
  availability: "", showWorkPreferences: false, highlights: "", training: "",
  memberships: "", openToEnquiries: false, showreel: "", featuredProjectId: "",
};

const partial: Profile = {
  ...empty, name: "Ananya Raman", username: "ananya_raman",
  industry: "Tamil", role: "Editor",
};

const fixtures: Record<"new" | "partial" | "complete", Profile> = {
  new: empty,
  partial,
  complete: {
    ...partial,
    additional: ["Malayalam"],
    photo: "sample-avatar",
    bio: "Film editor working across Tamil and Malayalam short films and documentaries.",
    headline: "Film editor specialising in documentaries and short films",
    specialisations: "Documentary editing, Story editing",
    languages: "Tamil, Malayalam, English",
    featuredProjectId: "sample-project",
    portfolio: [{
      id: "sample-project",
      title: "Between the Frames",
      role: "Editor",
      type: "Short film",
      year: "2025",
      url: "",
      contribution: "Edited the short film from assembly through final cut.",
    }],
  },
};

export function validSocial(value: string) {
  try {
    const url = new URL(value);
    return url.protocol === "https:" && !url.username && !url.password;
  } catch {
    return false;
  }
}

function ProfilePhoto({ photo }: { photo: string }) {
  if (photo === "sample-avatar") {
    return <span className={styles.avatar} role="img" aria-label="Sample profile photo placeholder">AR</span>;
  }

  return <img className={styles.avatar} src={photo} width={88} height={88} alt="Profile photo" />;
}

function portfolioIssue(project: PortfolioProject) {
  if (!project.title.trim() || !project.role.trim()) return "Enter a project title and your credited role.";
  if (project.year && !/^[0-9]{4}$/.test(project.year)) return "Enter a four-digit release year or leave it blank.";
  if (project.url && !validSocial(project.url)) return "Enter a valid HTTPS project link or leave it blank.";
  return "";
}

function stepIssues(profile: Profile) {
  return [
    !industries.includes(profile.industry) || !roles.includes(profile.role)
      ? "Select your primary industry and professional role."
      : "",
    !profile.name.trim() || !usernamePattern.test(profile.username)
      ? "Enter a display name and a valid username."
      : "",
    !profile.photo ? "Choose a profile photo." : "",
    !profile.bio.trim()
      ? "Enter your work bio."
      : [profile.social, ...Object.values(profile.socialLinks)].some((url) => url && !validSocial(url))
        ? "Enter valid HTTPS links or leave them blank." : "",
    profile.showreel && !validSocial(profile.showreel)
      ? "Enter a valid HTTPS showreel link or leave it blank."
      : profile.portfolio.map(portfolioIssue).find(Boolean) ?? "",
  ];
}

function nextIncomplete(profile: Profile) {
  const missing = stepIssues(profile).findIndex(Boolean);
  return missing === -1 ? reviewStep : missing;
}

export function profileCompletion(profile: Partial<Profile>, hasPhoto = Boolean(profile.photo)) {
  const checks = [
    industries.includes(profile.industry ?? ""),
    roles.includes(profile.role ?? ""),
    Boolean(profile.name?.trim()),
    usernamePattern.test(profile.username ?? ""),
    hasPhoto,
    Boolean(profile.bio?.trim()),
  ];

  return Math.round(checks.filter(Boolean).length * 100 / checks.length);
}

export type ProfessionalProfileDraft = { profile: Partial<Profile>; step: number };

function restoreServerDraft(draft: ProfessionalProfileDraft) {
  const profile = { ...empty, ...draft.profile };
  profile.username = normalizeUsername(profile.username);
  return { profile, step: Math.min(Math.max(0, draft.step), nextIncomplete(profile)) };
}

function readSavedDraft(key: string) {
  const raw = localStorage.getItem(key);
  if (!raw) return null;

  const saved = JSON.parse(raw) as Partial<Profile> & { wizardStep?: number };
  const profile = { ...empty, ...saved };
  profile.username = normalizeUsername(profile.username);
  const step = typeof saved.wizardStep === "number" && Number.isInteger(saved.wizardStep)
    ? Math.min(Math.max(0, saved.wizardStep), nextIncomplete(profile))
    : Math.min(portfolioStep, nextIncomplete(profile));

  return { profile, step };
}

export default function ProfileEditor({ userId, name, draft, editStep, editField, onExit }: {
  userId: number | string;
  name: string;
  draft: ProfessionalProfileDraft | null;
  editStep?: number;
  editField?: string | null;
  onExit: () => void;
}) {
  const profileStorageKey = `${draftKey}.user.${userId}`;
  const [profile, setProfile] = useState<Profile>(() => ({ ...empty, name }));
  const initialDraft = useRef(draft);
  const lastSavedStep = useRef(draft?.step ?? 0);
  const initialEditStep = useRef(editStep);
  const initialEditField = useRef(editField);
  const [saving, setSaving] = useState(false);
  const [checkedUsername, setCheckedUsername] = useState<{
    username: string;
    status: "available" | "taken" | "unreachable";
  } | null>(null);
  const [message, setMessage] = useState("");
  const [step, setStep] = useState(0);
  const [openProjectId, setOpenProjectId] = useState<string | null>(null);
  const activeProjectTitle = useRef<HTMLInputElement>(null);
  const focusProject = useRef(false);
  const [ready, setReady] = useState(false);
  const [error, setError] = useState("");
  const stepHeading = useRef<HTMLHeadingElement>(null);
  const focusStep = useRef(false);

  useEffect(() => {
    const timer = window.setTimeout(() => {
      try {
        const serverDraft = initialDraft.current;
        const saved = serverDraft ? restoreServerDraft(serverDraft) : readSavedDraft(profileStorageKey);
        if (saved) {
          setProfile(saved.profile);
          setStep(saved.step);
          setMessage(serverDraft
            ? "Resumed your account draft."
            : "Resumed your browser draft. Save it to your account to continue on another device.");
        }
      } catch {
        setMessage("The browser could not restore your saved draft.");
      }
      if (initialEditStep.current !== undefined) {
        setStep(initialEditStep.current);
      }
      setReady(true);
    }, 0);
    return () => window.clearTimeout(timer);
  }, [profileStorageKey]);

  useEffect(() => {
    if (!ready || initialEditStep.current !== step) return;

    const field = initialEditField.current;
    const target = field === "banner" || field === "photo" || field === "preferences" || field === "social-links"
      ? document.getElementById(`profile-edit-${field}`)
      : stepHeading.current;
    target?.focus();
  }, [ready, step]);

  const username = profile.username;
  const usernameIsValid = usernamePattern.test(username);
  const usernameStatus = usernameIsValid
    ? checkedUsername?.username === username ? checkedUsername.status : "checking"
    : null;

  useEffect(() => {
    if (!ready || step !== 1 || !usernameIsValid) return;

    const controller = new AbortController();
    const timer = window.setTimeout(async () => {
      const query = new URLSearchParams({ username });

      try {
        const response = await fetch(`/professional-profile/username-availability?${query}`, {
          headers: { Accept: "application/json" },
          signal: controller.signal,
          cache: "no-store",
        });

        if (!response.ok) throw new Error("Username availability request failed.");
        const result = await response.json();
        if (typeof result.available !== "boolean") throw new Error("Invalid availability response.");
        if (!controller.signal.aborted) {
          setCheckedUsername({ username, status: result.available ? "available" : "taken" });
        }
      } catch {
        if (!controller.signal.aborted) {
          setCheckedUsername({ username, status: "unreachable" });
        }
      }
    }, 350);

    return () => {
      window.clearTimeout(timer);
      controller.abort();
    };
  }, [ready, step, username, usernameIsValid]);

  useEffect(() => {
    if (focusStep.current) {
      stepHeading.current?.focus();
      focusStep.current = false;
    }
  }, [step]);
  useEffect(() => {
    if (focusProject.current) {
      activeProjectTitle.current?.focus();
      focusProject.current = false;
    }
  }, [openProjectId, step]);

  const [photoUploading, setPhotoUploading] = useState(false);
  const [fileInputKey, setFileInputKey] = useState(0);
  const completion = profileCompletion(profile);
  const invalidSocial = Boolean(profile.social) && !validSocial(profile.social);
  const currentIssue = stepIssues(profile)[step];
  const reviewReady = nextIncomplete(profile) === reviewStep;
  const featuredProject = profile.portfolio.find((project) => project.id === profile.featuredProjectId);
  const showcaseProjects = featuredProject
    ? [featuredProject, ...profile.portfolio.filter((project) => project.id !== featuredProject.id)]
    : profile.portfolio;

  function navigate(destination: number) {
    if (destination !== step) focusStep.current = true;
    setStep(destination);
    setError("");
  }

  function persist(destination: number, afterSave: () => void) {
    if (saving) return;

    setSaving(true);
    setError("");
    setMessage("");
    router.put("/professional-profile/draft", { profile, step: destination }, {
      preserveScroll: true,
      preserveState: true,
      onSuccess: () => {
        lastSavedStep.current = destination;
        setMessage("Draft saved to your FILMUNITY account.");
        afterSave();
      },
      onError: (errors) => {
        if (errors["profile.username"]) {
          if (errors["profile.username"] === "That username is already taken. Choose another.") {
            setCheckedUsername({ username: profile.username, status: "taken" });
          }
          navigate(1);
        }
        setError(Object.values(errors).join(" ") || "Your draft could not be saved.");
      },
      onFinish: () => setSaving(false),
    });
  }

  function update(patch: Partial<Profile>) {
    setProfile((current) => ({ ...current, ...patch }));
    setMessage("");
    setError("");
  }

  function openProject(id: string) {
    if (id !== openProjectId) focusProject.current = true;
    setOpenProjectId(id);
  }

  function addProject() {
    if (profile.portfolio.length >= portfolioLimit) return;
    const project = {
      id: crypto.randomUUID(), title: "", role: "", type: "", year: "", url: "", contribution: "",
    };
    update({ portfolio: [...profile.portfolio, project] });
    openProject(project.id);
  }

  function updateProject(id: string, patch: Partial<PortfolioProject>) {
    update({ portfolio: profile.portfolio.map((project) => project.id === id ? { ...project, ...patch } : project) });
  }

  function loadFixture(key: keyof typeof fixtures) {
    const fixture = fixtures[key];
    setProfile(fixture);
    setOpenProjectId(null);
    setFileInputKey((value) => value + 1);
    navigate(nextIncomplete(fixture));
    setMessage("Sample loaded. Use Save and continue or Save and exit to replace the saved draft.");
  }

  function continueSetup(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (step === reviewStep || photoUploading || saving) return;
    if (currentIssue) {
      setError(currentIssue);
      if (step === portfolioStep) {
        if (profile.showreel && !validSocial(profile.showreel)) {
          event.currentTarget.querySelector<HTMLInputElement>("#profile-showreel")?.focus();
        } else {
          const invalid = profile.portfolio.find((project) => portfolioIssue(project));
          if (invalid) openProject(invalid.id);
        }
      }
      return;
    }

    persist(step + 1, () => navigate(step + 1));
  }

  function goBack() {
    navigate(step - 1);
  }

  async function readImage(kind: "photo" | "banner", input: HTMLInputElement) {
    const file = input.files?.[0];
    if (!file) return;

    const maxMb = kind === "banner" ? 2 : 1;
    if (!profileImageTypes.includes(file.type) || file.size > maxMb * 1024 * 1024) {
      input.value = "";
      setMessage(`Choose a JPEG, PNG or WebP image up to ${maxMb} MB.`);
      return;
    }

    setPhotoUploading(true);
    setMessage("");
    try {
      if (kind === "banner") {
        const image = await createImageBitmap(file);
        const { width, height } = image;
        image.close();
        if (width < 1200 || width > 6000 || width !== height * 3) {
          input.value = "";
          setMessage("Use a 3:1 banner between 1200 × 400 and 6000 × 2000 pixels.");
          return;
        }
      }

      const dataUrl = await readDataUrl(file);
      update({ [kind]: dataUrl });
    } catch {
      input.value = "";
      setMessage("The browser could not read the selected image.");
    } finally {
      setPhotoUploading(false);
    }
  }

  async function addHeroSlides(input: HTMLInputElement) {
    const files = Array.from(input.files ?? []);
    input.value = "";
    if (!files.length) return;
    if (profile.heroSlides.length + files.length > heroSlideLimit) {
      setMessage("Choose no more than 10 images in total.");
      return;
    }
    if (files.some((file) => !profileImageTypes.includes(file.type) || file.size > heroSlideMaxBytes)) {
      setMessage("Each slider image must be JPEG, PNG or WebP and no larger than 2 MB.");
      return;
    }

    setPhotoUploading(true);
    setMessage("");
    try {
      const images: string[] = [];
      for (const file of files) {
        images.push(await prepareHeroSlide(file));
      }
      update({ heroSlides: [...profile.heroSlides, ...images] });
    } catch {
      setMessage("One or more slider images could not be prepared. Try selecting a smaller image.");
    } finally {
      setPhotoUploading(false);
    }
  }

  return (
    <div className={styles.wizard} data-theme="dark">
      <p className={styles.status}>Profile drafts save to your FILMUNITY account.</p>
      {!ready ? <p role="status">Opening profile setup…</p> : (
        <>
      <div className={`${styles.editor}`}>
        <aside className={styles.card} aria-label="Setup progress">
          <p className={styles.stepCount}>Step {step + 1} of {steps.length}</p>
          <p className={styles.percent}>{completion}% complete</p>
          <progress className={styles.wizardProgress} aria-label="Profile completion" value={completion} max={100} />
          <nav aria-label="Professional profile sections">
            <ol className={styles.stepList}>
              {steps.map((label, index) => (
                <li key={label}>
                  <button
                    type="button"
                    className={styles.stepButton}
                    aria-current={index === step ? "step" : undefined}
                    aria-controls="profile-step-panel"
                    disabled={saving || photoUploading}
                    onClick={() => navigate(index)}
                  >
                    <span className={styles.stepNumber} aria-hidden="true">{index + 1}</span>
                    <span className={styles.stepLabel}>{label}</span>
                  </button>
                </li>
              ))}
            </ol>
          </nav>
        </aside>
        <form id="profile-step-panel" className={styles.card} onSubmit={continueSetup} noValidate>
          <h2 id="profile-step-title" ref={stepHeading} tabIndex={-1}>{steps[step]}</h2>
          {step === 0 && (
            <fieldset className={styles.stepFields} aria-labelledby="profile-step-title">
            <label className={styles.field}>Primary industry
              <select value={profile.industry} onChange={(event) => {
                const industry = event.target.value;
                update({ industry, additional: profile.additional.filter((item) => item !== industry) });
              }}>
                <option value="">Select industry</option>
                {industries.map((industry) => <option key={industry}>{industry}</option>)}
              </select>
            </label>
            <div role="group" aria-label="Additional industries" className={styles.group}>
              <span>Additional industries (optional)</span>
              <div className={styles.choices}>
                {industries.filter((industry) => industry !== profile.industry).map((industry) => (
                  <label key={industry}>
                    <input type="checkbox" checked={profile.additional.includes(industry)} onChange={(event) =>
                      update({ additional: event.target.checked
                        ? [...profile.additional, industry]
                        : profile.additional.filter((item) => item !== industry) })
                    } /> {industry}
                  </label>
                ))}
              </div>
            </div>
            <label className={styles.field}>Professional role
              <SearchableSelect aria-label="Professional role" placeholder="Select role" value={profile.role}
                options={[...(roleCatalog.legacy.includes(profile.role) ? [{ value: profile.role, label: `${profile.role} (saved role)` }] : []), ...Object.entries(roleCatalog.groups).flatMap(([group, items]) => items.map((role) => ({ value: role, label: role, keywords: group })))]}
                onValueChange={(role) => update({ role })} />
            </label>
            <label className={styles.field}>Specialisations (optional)
              <input value={profile.specialisations} maxLength={160} onChange={(event) => update({ specialisations: event.target.value })} />
            </label>
            <label className={styles.field}>Working languages (optional)
              <input value={profile.languages} maxLength={120} onChange={(event) => update({ languages: event.target.value })} />
            </label>
            </fieldset>
          )}
          {step === 1 && (
            <fieldset className={styles.stepFields} aria-labelledby="profile-step-title">
            <label className={styles.field}>Display name
              <input value={profile.name} maxLength={255} onChange={(event) => update({ name: event.target.value })} />
            </label>
            <label className={styles.field}>Username
              <input value={profile.username} maxLength={31} autoCapitalize="none" spellCheck={false}
                aria-describedby="profile-username-help profile-username-status"
                aria-invalid={usernameStatus === "taken" || (username !== "" && !usernameIsValid)}
                onChange={(event) => {
                  setCheckedUsername(null);
                  update({ username: normalizeUsername(event.target.value) });
                }} />
              <small id="profile-username-help">Use 3–30 letters, numbers or underscores. The @ is added to your profile link.</small>
              <small id="profile-username-status" role="status" className={styles.status}>
                {usernameStatus === "checking" && "Checking availability…"}
                {usernameStatus === "available" && `@${username} is available.`}
                {usernameStatus === "taken" && `@${username} is already taken.`}
                {usernameStatus === "unreachable" && "Availability will be checked when you save."}
              </small>
            </label>
            <label className={styles.field}>Professional headline (optional)
              <input value={profile.headline} maxLength={120} onChange={(event) => update({ headline: event.target.value })} />
            </label>
            <details className={styles.profileExtras} open={initialEditField.current === "preferences" || undefined}>
              <summary id="profile-edit-preferences">Location, availability and enquiries</summary>
              <div className={styles.detailFields}>
                <label className={styles.field}>Base city (optional)
                  <input value={profile.city} maxLength={100} onChange={(event) => update({ city: event.target.value })} />
                </label>
                <label className={styles.field}>Travel preferences (optional)
                  <input value={profile.travel} maxLength={120} onChange={(event) => update({ travel: event.target.value })} />
                </label>
                <label className={styles.field}>Availability (optional)
                  <input value={profile.availability} maxLength={120} onChange={(event) => update({ availability: event.target.value })} />
                </label>
                  <div className={styles.choices}>
                    <label><input type="checkbox" checked={profile.showWorkPreferences} onChange={(event) => update({ showWorkPreferences: event.target.checked })} /> Show location and availability on my showcase</label>
                    <label><input type="checkbox" checked={profile.openToEnquiries} onChange={(event) => update({ openToEnquiries: event.target.checked })} /> Show that I’m open to project enquiries</label>
                  </div>
              </div>
            </details>
            </fieldset>
          )}
          {step === 2 && <PassportPhotoUpload />}
          {step === 2 && (
            <fieldset className={styles.stepFields} aria-labelledby="profile-step-title">
            <div className={styles.mediaField}>
              {profile.photo && (
                <ProfilePhoto photo={profile.photo} />
              )}
              <label className={styles.field}>Profile photo
                <input id="profile-edit-photo" key={fileInputKey} type="file" accept="image/jpeg,image/png,image/webp" disabled={photoUploading || saving}
                  onChange={(event) => void readImage("photo", event.currentTarget)} />
                <small>JPEG, PNG or WebP up to 1 MB.</small>
              </label>
              {profile.photo && <button type="button" className={styles.secondary} disabled={photoUploading || saving} onClick={() => {
                update({ photo: "" });
                setFileInputKey((value) => value + 1);
              }}>Remove photo</button>}
            </div>
            <div className={styles.mediaField}>
              {profile.banner && <img className="aspect-3/1 w-full rounded-lg object-cover" src={profile.banner} alt="Profile banner preview" />}
              <label className={styles.field}>Profile banner (optional)
                <input id="profile-edit-banner" type="file" accept="image/jpeg,image/png,image/webp"
                  disabled={photoUploading || saving} onChange={(event) => void readImage("banner", event.currentTarget)} />
                <small>JPEG, PNG or WebP up to 2 MB. Use a 3:1 image, 1200 × 400 to 6000 × 2000 px. Recommended 1500 × 500 px.</small>
              </label>
              {profile.banner && <button type="button" className={`${styles.secondary} justify-self-start`} disabled={photoUploading || saving} onClick={() => {
                update({ banner: "" });
                const input = document.getElementById("profile-edit-banner");
                if (input instanceof HTMLInputElement) input.value = "";
              }}>Remove banner</button>}
            </div>
            </fieldset>
          )}
          {step === 3 && (
            <fieldset className={styles.stepFields} aria-labelledby="profile-step-title">
            <label className={styles.field}>Work bio
              <textarea rows={5} maxLength={1500} value={profile.bio} onChange={(event) => update({ bio: event.target.value })} />
            </label>
            <label className={styles.field}>Website or other link (optional)
              <input type="url" value={profile.social} aria-invalid={invalidSocial}
                aria-describedby={invalidSocial ? "profile-link-error" : undefined}
                onChange={(event) => update({ social: event.target.value.trim() })} />
              {invalidSocial && <small id="profile-link-error" className={styles.error}>Enter an HTTPS URL.</small>}
            </label>
            <fieldset className={styles.socialFields}>
              <legend id="profile-edit-social-links" tabIndex={-1} className="px-2 font-medium">Social media links</legend>
              <div className={styles.detailFields}>
                {socialKeys.map((platform) => (
                  <label key={platform} className={styles.field}>{socialPlatforms[platform]}
                    <input type="url" maxLength={2048} value={profile.socialLinks[platform] ?? ""} placeholder="https://…"
                      onChange={(event) => update({ socialLinks: { ...profile.socialLinks, [platform]: event.target.value.trim() } })} />
                  </label>
                ))}
              </div>
            </fieldset>
            <details className={styles.profileExtras}>
              <summary>Credits and credentials</summary>
              <div className={styles.detailFields}>
                <label className={styles.field}>Awards and festival selections (optional)
                  <input value={profile.highlights} maxLength={240} onChange={(event) => update({ highlights: event.target.value })} />
                </label>
                <label className={styles.field}>Training (optional)
                  <input value={profile.training} maxLength={240} onChange={(event) => update({ training: event.target.value })} />
                </label>
                <label className={styles.field}>Association memberships (optional)
                  <input value={profile.memberships} maxLength={240} onChange={(event) => update({ memberships: event.target.value })} />
                </label>
                  <p className={styles.status}>Memberships entered here are self-reported.</p>
              </div>
            </details>
            </fieldset>
          )}
          {step === portfolioStep && (
            <div className={styles.portfolio}>
              <p className={styles.status}>Optional. Add up to six projects that show the work you want to be hired for.</p>
              <label className={styles.field}>Showreel link (optional)
                <input id="profile-showreel" type="url" value={profile.showreel} placeholder="https://…" aria-invalid={Boolean(profile.showreel) && !validSocial(profile.showreel)}
                  onChange={(event) => update({ showreel: event.target.value.trim() })} />
              </label>
              {profile.portfolio.map((project, index) => (
                <div className={styles.projectEntry} key={project.id}>
                  <button
                    type="button"
                    className={styles.projectHeader}
                    aria-expanded={openProjectId === project.id}
                    aria-controls={`project-${project.id}`}
                    onClick={() => {
                      if (openProjectId === project.id) setOpenProjectId(null);
                      else openProject(project.id);
                    }}
                  >
                    <span><strong>{project.title.trim() || `Project ${index + 1}`}</strong>
                      <small>{portfolioIssue(project) ? "Incomplete" : [project.role, project.type, project.year].filter(Boolean).join(" · ")}</small>
                    </span>
                    <span>{openProjectId === project.id ? "Hide" : "Edit"}</span>
                  </button>
                  <div id={`project-${project.id}`} hidden={openProjectId !== project.id}>
                    {openProjectId === project.id && (
                        <fieldset className={styles.projectCard}>
                          <legend>Project details</legend>
                          <div className={styles.projectFields}>
                            <label className={styles.field}>Project title
                              <input ref={activeProjectTitle} value={project.title} maxLength={120} required onChange={(event) => updateProject(project.id, { title: event.target.value })} />
                            </label>
                            <label className={styles.field}>Credited role
                              <input value={project.role} maxLength={100} required onChange={(event) => updateProject(project.id, { role: event.target.value })} />
                            </label>
                            <label className={styles.field}>Project type (optional)
                              <select value={project.type} onChange={(event) => updateProject(project.id, { type: event.target.value })}>
                                <option value="">Select type</option>
                                {projectTypes.map((type) => <option key={type}>{type}</option>)}
                              </select>
                            </label>
                            <label className={styles.field}>Release year (optional)
                              <input value={project.year} maxLength={4} inputMode="numeric" onChange={(event) => updateProject(project.id, { year: event.target.value })} />
                            </label>
                            <label className={styles.field}>Project link (optional)
                              <input type="url" value={project.url} placeholder="https://…" onChange={(event) => updateProject(project.id, { url: event.target.value.trim() })} />
                            </label>
                            <label className={styles.field}>Your contribution (optional)
                              <textarea rows={3} maxLength={400} value={project.contribution} onChange={(event) => updateProject(project.id, { contribution: event.target.value })} />
                            </label>
                          </div>
                          <div className={styles.choices}>
                            <label>
                              <input type="checkbox" checked={profile.featuredProjectId === project.id} onChange={(event) => update({ featuredProjectId: event.target.checked ? project.id : "" })} />
                              Feature this project
                            </label>
                          </div>
                          <button type="button" className={styles.secondary} onClick={() => {
                            update({
                              portfolio: profile.portfolio.filter((item) => item.id !== project.id),
                              featuredProjectId: profile.featuredProjectId === project.id ? "" : profile.featuredProjectId,
                            });
                            setOpenProjectId(null);
                          }}>Remove project {index + 1}</button>
                        </fieldset>
                    )}
                  </div>
                </div>
              ))}
              <button type="button" className={styles.secondary} disabled={profile.portfolio.length >= portfolioLimit} onClick={addProject}>Add a project</button>
            </div>
          )}
          {step === heroSliderStep && (
            <fieldset className={styles.stepFields} aria-labelledby="profile-step-title">
              <label className={styles.field}>Home slider images (optional)
                <input type="file" accept="image/jpeg,image/png,image/webp" multiple
                  disabled={photoUploading || saving || profile.heroSlides.length >= heroSlideLimit}
                  aria-describedby="hero-slider-help"
                  onChange={(event) => void addHeroSlides(event.currentTarget)} />
                <small id="hero-slider-help">Add up to 10 JPEG, PNG or WebP images, up to 2 MB each. Larger images are optimized for the web. Wide images work best.</small>
              </label>
              <p className={styles.status}>{profile.heroSlides.length} of 10 images. Slides show in upload order.</p>
              {profile.heroSlides.length > 0 && (
                <ol className={styles.slideGrid}>
                  {profile.heroSlides.map((src, index) => (
                    <li key={index} className="grid min-w-0 gap-2 rounded-lg border border-border p-2">
                      <img className={styles.slideThumbnail} src={src} alt={`Slider image ${index + 1}`} />
                      <button type="button" className={styles.secondary} disabled={photoUploading || saving}
                        aria-label={`Remove slider image ${index + 1}`}
                        onClick={() => update({ heroSlides: profile.heroSlides.filter((_, position) => position !== index) })}>
                        Remove
                      </button>
                    </li>
                  ))}
                </ol>
              )}
            </fieldset>
          )}
          {step === reviewStep && (
            <section className={styles.showcase} aria-label="Showcase preview">
              {reviewReady ? (
                <>
                  <p className={styles.status}>Preview · /community/@{profile.username}</p>
                  {(profile.heroSlides.length > 0 || profile.banner) && (
                    <div className={styles.reviewWide}>
                      <ProfileHeroPreview slides={profile.heroSlides} fallback={profile.banner} />
                    </div>
                  )}
                  <div className={styles.mediaField}>
                    <ProfilePhoto photo={profile.photo} />
                    <h3>{profile.name}</h3>
                    {profile.headline.trim() && <p>{profile.headline}</p>}
                    <p>{[profile.role, profile.industry, ...profile.additional].join(" · ")}</p>
                  </div>
                  <div className={styles.mediaField}>
                    <p className={styles.bio}>{profile.bio}</p>
                    {[
                      ["Specialisations", profile.specialisations],
                      ["Working languages", profile.languages],
                      ["Awards and festival selections", profile.highlights],
                      ["Training", profile.training],
                      ["Self-reported memberships", profile.memberships],
                      ...(profile.showWorkPreferences ? [
                        ["Base city", profile.city],
                        ["Travel preferences", profile.travel],
                        ["Availability", profile.availability],
                      ] : []),
                    ].filter(([, value]) => value.trim()).map(([label, value]) => (
                      <p key={label}><strong>{label}</strong> · {value}</p>
                    ))}
                    {profile.openToEnquiries && <p><strong>Open to project enquiries</strong></p>}
                    {profile.showreel && <a href={profile.showreel}>Watch showreel</a>}
                    {profile.social && <a href={profile.social}>Website</a>}
                    {socialKeys.map((platform) => {
                      const link = profile.socialLinks[platform];
                      return link && validSocial(link) ? <a key={platform} href={link}>{socialPlatforms[platform]}</a> : null;
                    })}
                  </div>
                  {profile.portfolio.length > 0 && (
                    <section className={`${styles.portfolio} ${styles.reviewWide}`} aria-label="Selected work">
                      <h3>Selected work</h3>
                      {showcaseProjects.map((project) => (
                        <article className={styles.projectCard} key={project.id}>
                          <h4>{project.title.trim()}</h4>
                          {project.id === profile.featuredProjectId && <p className={styles.status}>Featured project</p>}
                          <p>{[project.role.trim(), project.type, project.year].filter(Boolean).join(" · ")}</p>
                          {project.contribution.trim() && <p className={styles.bio}>{project.contribution}</p>}
                          {project.url && <a href={project.url}>View project</a>}
                        </article>
                      ))}
                    </section>
                  )}
                  <p className={`${styles.status} ${styles.reviewWide}`}>This showcase is a private preview.</p>
                </>
              ) : (
                <p>Finish the earlier steps to see your showcase.</p>
              )}
            </section>
          )}
          {error && <p className={styles.wizardError} role="alert">{error}</p>}
          <div className={styles.wizardActions}>
            {step > 0 && <button type="button" className={styles.secondary} disabled={photoUploading || saving} onClick={goBack}>Back</button>}
            <button type="button" className={step === reviewStep ? styles.primary : styles.secondary} disabled={photoUploading || saving} onClick={() => persist(initialEditStep.current !== undefined ? lastSavedStep.current : step, onExit)}>Save and exit</button>
            {step < reviewStep && (
              <button type="submit" className={styles.primary} disabled={photoUploading || saving}>
                {step === portfolioStep && profile.portfolio.length === 0 ? "Continue without projects" : "Save and continue"}
              </button>
            )}
          </div>
          <p role="status" className={styles.status}>{saving ? "Saving draft…" : message}</p>
        </form>
      </div>
      <details className={styles.samples}>
        <summary>Preview sample profiles</summary>
        <div className={styles.actions}>
          <button type="button" disabled={photoUploading || saving} onClick={() => loadFixture("new")}>New</button>
          <button type="button" disabled={photoUploading || saving} onClick={() => loadFixture("partial")}>Partial</button>
          <button type="button" disabled={photoUploading || saving} onClick={() => loadFixture("complete")}>Complete</button>
        </div>
      </details>
        </>
      )}
    </div>
  );
}
