<?php

namespace Admin\Controllers;

use App\Controllers\BaseController;
use CodeIgniter\HTTP\ResponseInterface;
use App\Models\UserModel;
use App\Models\Company;
use CodeIgniter\Shield\Exceptions\ValidationException;
use CodeIgniter\Events\Events;
use CodeIgniter\Shield\Authentication\Authenticators\Session;
use CodeIgniter\Shield\Validation\ValidationRules;
use CodeIgniter\Shield\Entities\User;

class Users extends BaseController
{

    public function __construct() {
        $this->db = \Config\Database::connect();

    }

    public function index()
    {
        $title = 'User List';
        $userModel = new UserModel();

        // Get current user id
        $currentUserId = $userModel->find(auth()->user()->id);

        // Display all user if current user is superadmin
        if (auth()->user()->inGroup('superadmin')) {
            $userData = $userModel
            ->select('users.*, company.*, identities.secret, groups_users.group') 
            ->join('company', 'users.comp_id = company.comp_id')
            ->join('identities', 'users.id = identities.user_id')
            ->join('groups_users', 'users.id = groups_users.user_id')
            ->where('users.id !=', $currentUserId->id)
            ->get()
            ->getResult();
            return view('Admin\Views\UsersView',compact('title','userData'));
        }
        
        // Get current user company ID
        $currentUserCompId = $currentUserId->comp_id;

        $userData = $userModel
            ->select('users.*, company.*, identities.secret, groups_users.group') 
            ->join('company', 'users.comp_id = company.comp_id')
            ->join('identities', 'users.id = identities.user_id')
            ->join('groups_users', 'users.id = groups_users.user_id') 
            ->where('users.comp_id', $currentUserCompId)
            ->where('users.id !=', $currentUserId->id) 
            ->get()
            ->getResult();

        return view('Admin\Views\UsersView',compact('title','userData'));
    }

    public function verifyUser($id) {

        $userData = $this->db->query(
            "SELECT users.*, company.*
            FROM users
            INNER JOIN company ON users.comp_id=company.comp_id
            WHERE users.id = $id;"
        )->getResult();

        if (empty($userData)) {
            // Handle case where no user found with the ID
            return redirect()->to(base_url('Admin/users')); // Or display error message
        }

        # Update company status based on comp_id from users table
        $compId = isset($userData[0]->comp_id) ? $userData[0]->comp_id : null;

        if ($compId) {
            $companyData = [
                'status' => 'verified', // Replace with actual field and value
            ];
            $this->db->table('company')
                ->where('comp_id', $compId)
                ->update($companyData);
        }
        
        return redirect()->to(base_url('Admin/users'));
    }

    public function addNewUser() {
        $title = 'Add User';
        $companyModel = new Company();
        $userModel = new UserModel();

        // Superadmin will fetch all company
        if (auth()->user()->inGroup('superadmin')) {
            $companyData = $companyModel->where('status','verified')->findAll();
        } else {
            $currentUserId = $userModel->find(auth()->user()->id);
            $currentUserComp = $currentUserId->comp_id;
            $companyData = $companyModel->find($currentUserComp);
        }

        return view('Admin\Views\AddNewUserView', compact('title', 'companyData'));
    }

    public function saveUser() {

        // Declare object for Model
        $users = $this->getUserProvider();
        $companyModel = new Company();

        // Validation rule
        $rules = [
            'comp_reg_no' => [
                'label' => 'Company Registration No',
                'rules' => [
                    'required',
                    'max_length[30]',
                    'min_length[3]',
                ],
            ],
            'comp_name' => [
                'label' => 'Company Name',
                'rules' => [
                    'required',
                    'min_length[3]',
                ],
            ],
            'username' => [
                'label' => 'Auth.username',
                'rules' => [
                    'required',
                    'max_length[30]',
                    'min_length[3]',
                    'is_unique[users.username]',
                ],
            ],
            'email' => [
                'label' => 'Auth.email',
                'rules' => [
                    'required',
                    'max_length[254]',
                    'valid_email',
                    'is_unique[identities.secret]',
                ],
            ],
            'password' => [
                'label' => 'Auth.password',
                'rules' => 'required|max_byte[72]|strong_password[]',
                'errors' => [
                    'max_byte' => 'Auth.errorPasswordTooLongBytes'
                ]
            ],
            'password_confirm' => [
                'label' => 'Auth.passwordConfirm',
                'rules' => 'required|matches[password]',
            ],
        ];

        // If validation fail return back to the addUser page 
        if (! $this->validateData($this->request->getPost(), $rules, [], config('Auth')->DBGroup)) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        // Get user company registration number from user input
        $compRegNum = $this->request->getPost('comp_reg_no');
        // Get data of company with same comp_reg_no from company table 
        $compId = $companyModel->where('comp_reg_no', $compRegNum)->first();

        // Save the user
        $allowedPostFields = array_keys($rules);
        $user              = $this->getUserEntity();
        $user->fill($this->request->getPost($allowedPostFields));
        $user->comp_id = $compId['comp_id'];
        // Get user role from user input
        $role = [$this->request->getPost('role')];

        # Save data to users table
        try {
            $users->save($user);
        } catch (ValidationException $e) {
            return redirect()->back()->withInput();
        }

        // To get the complete user object with ID, we need to get from the database
        $user = $users->findById($users->getInsertID());

        // Add to user group
        $user->syncGroups(...$role);

        Events::trigger('register', $user);

        /** @var Session $authenticator */
        $authenticator = auth('session')->getAuthenticator();

        //$authenticator->startLogin($user);

        // If an action has been defined for register, start it up.
        $hasAction = $authenticator->startUpAction('register', $user);
        if ($hasAction) {
            return redirect()->route('auth-action-show');
        }
        
        // Set the user active
        $user->activate();

        //$authenticator->completeLogin($user);

        // Send email to the new user
        $email = \Config\Services::email();
        $email->setTo('muhdizat.h@gmail.com'); // Replace with your actual email address
        $email->setSubject('Test Email from CodeIgniter 4');
        $email->setMessage('This is a test email sent using MailEnable.');

        if ($email->send()) {

            // Success!
            $session = session();
            //$session->setFlashdata('success', "Registration successful!  We'll email you once your account is verified for login.");
            return redirect()->to('Admin/users');
        } else {
            return redirect()->back()
            ->with('error', 'Failed to send registration email to admin');
        }

    }

    /**
     * Returns the User provider
     */
    protected function getUserProvider(): UserModel
    {
        $provider = model(setting('Auth.userProvider'));

        assert($provider instanceof UserModel, 'Config Auth.userProvider is not a valid UserProvider.');

        return $provider;
    }

    /**
     * Returns the rules that should be used for validation.
     *
     * @return array<string, array<string, list<string>|string>>
     */
    protected function getValidationRules(): array
    {
        $rules = new ValidationRules();

        return $rules->getRegistrationRules();
    }

    /**
     * Returns the Entity class that should be used
     */
    protected function getUserEntity(): User
    {
        return new User();
    }

    public function editUser($id) {
        $title = 'Edit User';

        $userModel = new UserModel();

        $userData = $userModel
        ->select('users.*, company.*, identities.secret, groups_users.group') 
        ->join('company', 'users.comp_id = company.comp_id')
        ->join('identities', 'users.id = identities.user_id')
        ->join('groups_users', 'users.id = groups_users.user_id') 
        ->where('users.id', $id) 
        ->get()
        ->getResult();
        //dd($userData);

        return view('Admin\Views\editUserView', compact('title','userData'));
    }

    public function saveEditUser($id) {
        // Declare object for Model
        $userModel = $this->getUserProvider();

        // Validation rule
        /*$rules = [
            'username' => 'required|max_length[30]|min_length[3]|is_unique[users.username]',
            'email' => 'required|max_length[254]|valid_email|is_unique[identities.secret]'
                
        ];

        // If validation fail return back to the addUser page 
        if (! $this->validateData($this->request->getPost(array_keys($rules)), $rules)) {
            $errors = $this->validator->getErrors();
            return redirect()->back()->withInput()->with('errors', $errors);
        }*/

        $userData = [
            'username' => $this->request->getPost('username'),
            'email' => $this->request->getPost('email')
        ];
        
        // Find the user to be edited
        $user = $userModel->find($id);
        if (!$user) {
            // Handle user not found
            return redirect()->back()->with('error', 'User not found');
        }
        
        // Update user data
        $user->fill($userData);
        
        $role = [$this->request->getPost('role')];
        $user->syncGroups(...$role);
        
        // Save the updated user
        try {
            $userModel->save($user);
            return redirect()->to('Admin/users');
        } catch (ValidationException $e) {
            return redirect()->back()->withInput();
        }
    }

    public function deleteUser($id) {
        $userModel = new UserModel();
        $userModel->delete($id);
        //dd($id);
        return redirect()->to('Admin/users');
    }
}
